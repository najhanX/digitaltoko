<?php

namespace Tests\Feature;

use App\Jobs\RefreshSupplierWallet;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\User;
use App\Services\SupplierWallets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupplierWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'cache.default' => 'array', 'suppliers.cache_store' => 'array', 'inertia.ssr.enabled' => false,
            'services.hanshop.url' => 'https://hanshop.example', 'services.hanshop.token' => str_repeat('a', 40)]);
        Http::preventStrayRequests();
        Cache::flush();
    }

    private function connection(string $kind = 'digiflazz', ?Store $store = null): SupplierConnection
    {
        $store ??= Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.(User::count() + 1)])->id,
            'name' => 'Toko Wallet', 'slug' => 'wallet-'.Str::random(10), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'provisioning', 'template' => 'aurora']);

        return SupplierConnection::create(['store_id' => $store->id, 'kind' => $kind, 'name' => $kind.' Wallet', 'slot' => Str::uuid(), 'enabled' => true,
            'credentials' => ['username' => 'demo', 'api_key' => 'test-key', 'member_id' => 'DEMO', 'pin' => '1111', 'password' => 'test-only'],
            'settings' => ['base_url' => 'https://8.8.8.8:6969']]);
    }

    public function test_panel_is_scoped_paginated_and_does_not_call_suppliers_on_open(): void
    {
        $connection = $this->connection();
        $this->connection('hanshop', $connection->store);
        $other = $this->connection('otomax');
        $url = '/stores/'.$connection->store_id.'/supplier-wallets';
        $this->actingAs($connection->store->owner)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Owner/SupplierWallets')->has('items', 2)->where('pagination.total', 2)->missing('items.0.credentials')->missing('items.0.identity_key'));
        $this->get($url.'?q=hanshop')->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.kind', 'hanshop'));
        $this->get('/stores/'.$other->store_id.'/supplier-wallets')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_staff_cannot_read_or_deposit_shared_hanshop_wallet(): void
    {
        $connection = $this->connection('hanshop');
        $staff = StoreStaff::create(['store_id' => $connection->store_id, 'name' => 'Staff', 'username' => 'walletstaff', 'email' => 'staff@example.test', 'password' => 'password', 'active' => true, 'permissions' => ['suppliers.balance', 'suppliers.deposit']]);
        $this->actingAs($staff, 'staff')->get('/stores/'.$connection->store_id.'/supplier-wallets')->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 0));
        $this->post('/stores/'.$connection->store_id.'/supplier-wallets/'.$connection->id.'/refresh')->assertForbidden();
        $this->post('/stores/'.$connection->store_id.'/supplier-wallets/'.$connection->id.'/deposit', ['amount' => 50000, 'request_id' => Str::uuid()])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_events_never_regress_balance_and_equal_times_require_confirmation(): void
    {
        $connection = $this->connection();
        $wallets = app(SupplierWallets::class);
        $time = now()->subMinute();
        $wallets->observe($connection, 900, $time, 'callback');
        $wallets->observe($connection, 1011, $time->copy()->subSecond(), 'callback');
        $this->assertSame(900, $wallets->account($connection)->balance);
        $wallets->observe($connection, 900, $time, 'callback');
        $this->assertFalse($wallets->account($connection)->needs_check);
        $wallets->observe($connection, 800, $time, 'callback');
        $this->assertTrue($wallets->account($connection)->needs_check);
        $wallets->observe($connection, 0, $time->copy()->addSecond(), 'callback');
        $this->assertSame(0, $wallets->account($connection)->balance);
        $wallets->observe($connection, 700, null, 'callback');
        $wallets->observe($connection, 1000, now()->addHour(), 'callback');
        $this->assertSame(0, $wallets->account($connection)->balance);
        $this->assertTrue($wallets->account($connection)->needs_check);
    }

    public function test_account_identity_shares_only_with_same_owner_account_and_mode(): void
    {
        $connection = $this->connection();
        $otherStore = $connection->store->replicate();
        $otherStore->slug = 'other-store';
        $otherStore->save();
        $same = $this->connection('digiflazz', $otherStore);
        $wallets = app(SupplierWallets::class);
        $this->assertSame($wallets->account($connection)->id, $wallets->account($same)->id);
        $this->assertNotSame($wallets->identity($connection), $wallets->identity($this->connection()));
        $otherStore->update(['sandbox' => true]);
        $this->assertNotSame($wallets->identity($connection), $wallets->identity($same->fresh()));
        $same->update(['credentials' => ['username' => 'another', 'api_key' => 'another-key']]);
        $this->assertNotSame($wallets->identity($connection), $wallets->identity($same->fresh()));
    }

    public function test_balance_is_cached_and_failure_retains_last_amount(): void
    {
        $connection = $this->connection();
        Http::fake(['api.digiflazz.com/*' => Http::sequence()->push(['data' => ['deposit' => 50000]])->push([], 503)]);
        $wallets = app(SupplierWallets::class);
        $this->assertSame(50000, $wallets->refresh($connection)->balance);
        $this->assertSame(50000, $wallets->refresh($connection)->balance);
        Http::assertSentCount(1);
        Cache::flush();
        try {
            $wallets->refresh($connection);
            $this->fail('Expected failure');
        } catch (ValidationException) {
        }
        $account = $wallets->account($connection);
        $this->assertSame(50000, $account->balance);
        $this->assertTrue($account->needs_check);
        $this->assertNotNull($account->last_error);
    }

    public function test_deposit_digiflazz_is_idempotent_and_preserves_transfer_instructions(): void
    {
        $connection = $this->connection();
        Http::fake(['api.digiflazz.com/v1/deposit' => Http::response(['data' => ['rc' => '00', 'bank' => 'BCA', 'account_no' => '123456', 'notes' => 'NEWS', 'amount' => 200123]])]);
        $data = ['amount' => 200000, 'request_id' => (string) Str::uuid(), 'bank' => 'BCA', 'owner_name' => 'Demo'];
        $wallets = app(SupplierWallets::class);
        $first = $wallets->deposit($connection, $data);
        $second = $wallets->deposit($connection, $data);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('instructions', $first->status);
        $this->assertSame(200123, $first->details['total']);
        Http::assertSent(fn ($request) => $request['sign'] === md5('demotest-keydeposit') && $request['bank'] === 'BCA');
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('NEWS', (string) DB::table('supplier_deposits')->value('details'));
    }

    public function test_sandbox_deposits_never_contact_real_suppliers(): void
    {
        foreach (['digiflazz', 'hanshop', 'otomax'] as $kind) {
            $connection = $this->connection($kind);
            $connection->store->update(['sandbox' => true]);
            $deposit = app(SupplierWallets::class)->deposit($connection->fresh(), ['amount' => 200000, 'request_id' => (string) Str::uuid()]);
            $this->assertSame('simulated', $deposit->status);
        }
        Http::assertNothingSent();
    }

    public function test_hanshop_deposit_check_never_recreates_invoice_or_credits_local_wallet(): void
    {
        $connection = $this->connection('hanshop');
        Http::fake(['*/supplier/deposit' => Http::response(['status' => 'SUCCESS', 'data' => ['status' => 'PENDING', 'total' => 50123, 'qr_url' => 'https://example.test/qr.png']]),
            '*/supplier/deposit-status' => Http::response(['status' => 'SUCCESS', 'data' => ['status' => 'SUKSES', 'total' => 50123]])]);
        $wallets = app(SupplierWallets::class);
        $record = $wallets->deposit($connection, ['amount' => 50000, 'request_id' => (string) Str::uuid()]);
        $wallets->checkDeposit($record, $connection);
        $this->assertSame('paid', $record->fresh()->status);
        $this->assertDatabaseCount('customer_ledgers', 0);
        $this->assertNull($wallets->account($connection)->balance);
        Http::assertSentCount(2);
    }

    public function test_otomax_time_capture_validates_timezone_date_and_year_rollover(): void
    {
        Carbon::setTestNow('2027-01-01T00:01:00+07:00');
        try {
            $wallets = app(SupplierWallets::class);
            $time = $wallets->eventTime(['tanggal' => '31/12', 'jam' => '23:59:00']);
            $this->assertSame('2026-12-31 23:59:00', $time?->format('Y-m-d H:i:s'));
            $this->assertNull($wallets->eventTime(['tanggal' => '31/02', 'jam' => '23:59:00']));
            $this->assertNull($wallets->eventTime(['jam' => '23:59:00']));
            $this->assertNull($wallets->eventTime(['tanggal' => '01/01', 'jam' => '99:59:00']));
            $this->assertSame('2027-01-01T01:00:00+08:00', $wallets->eventTime(['tanggal' => '01/01', 'jam' => '01:00:00'], 'Asia/Makassar')?->toIso8601String());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unknown_deposit_is_not_resent_on_repeated_submit(): void
    {
        $connection = $this->connection();
        Http::fake(['*' => Http::response([], 503)]);
        $data = ['amount' => 200000, 'request_id' => (string) Str::uuid(), 'bank' => 'BCA', 'owner_name' => 'Demo'];
        $wallets = app(SupplierWallets::class);
        $this->assertSame('unknown', $wallets->deposit($connection, $data)->status);
        $this->assertSame('unknown', $wallets->deposit($connection, $data)->status);
        Http::assertSentCount(1);
    }

    public function test_explicit_deposit_rejection_is_not_reported_as_pending(): void
    {
        $connection = $this->connection();
        Http::fake(['*' => Http::response(['data' => ['rc' => '45']], 400)]);
        $record = app(SupplierWallets::class)->deposit($connection, ['amount' => 200000, 'request_id' => (string) Str::uuid(), 'bank' => 'BCA', 'owner_name' => 'Demo']);
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('RC 45', $record->details['message']);
    }

    public function test_refresh_job_skips_inactive_store_and_keeps_sandbox_local(): void
    {
        $connection = $this->connection('hanshop');
        $connection->store->update(['status' => 'suspended_manual']);
        (new RefreshSupplierWallet($connection->id))->handle();
        $this->assertDatabaseCount('supplier_accounts', 0);
        $connection->store->update(['status' => 'provisioning', 'sandbox' => true]);
        (new RefreshSupplierWallet($connection->id))->handle();
        $account = app(SupplierWallets::class)->account($connection->fresh());
        $this->assertSame(1000000, $account->balance);
        $this->assertSame('simulation', $account->source);
        Http::assertNothingSent();
    }

    public function test_digiflazz_minimum_is_enforced_before_creating_ticket_or_contacting_supplier(): void
    {
        $connection = $this->connection();
        $data = ['amount' => 100000, 'request_id' => (string) Str::uuid(), 'bank' => 'BCA', 'owner_name' => 'Demo'];
        $this->actingAs($connection->store->owner)->post('/stores/'.$connection->store_id.'/supplier-wallets/'.$connection->id.'/deposit', $data)
            ->assertSessionHasErrors(['amount' => 'Minimal deposit Digiflazz adalah Rp200.000.']);
        try {
            app(SupplierWallets::class)->deposit($connection, $data);
            $this->fail('Expected minimum validation');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('amount', $error->errors());
        }
        $this->assertDatabaseCount('supplier_deposits', 0);
        Http::assertNothingSent();
    }

    public function test_digiflazz_ticket_rejection_64_is_definitive_and_not_resent(): void
    {
        $connection = $this->connection();
        Http::fake(['*' => Http::response(['data' => ['rc' => 64]], 400)]);
        $data = ['amount' => 200000, 'request_id' => (string) Str::uuid(), 'bank' => 'BCA', 'owner_name' => 'Demo'];
        $wallets = app(SupplierWallets::class);
        $record = $wallets->deposit($connection, $data);
        $this->assertSame('failed', $record->status);
        $this->assertStringContainsString('RC 64', $record->details['message']);
        $this->assertSame($record->id, $wallets->deposit($connection, $data)->id);
        Http::assertSentCount(1);
    }

    public function test_hanshop_schema_failure_is_explained_and_status_does_not_recreate_request(): void
    {
        $connection = $this->connection('hanshop');
        Http::fake(['*/supplier/deposit' => Http::response(['status' => 'FAILED', 'data' => ['code' => 'DEPOSIT_SCHEMA_NOT_READY']], 503),
            '*/supplier/deposit-status' => Http::response(['status' => 'FAILED', 'data' => ['code' => 'DEPOSIT_NOT_FOUND']], 404)]);
        $wallets = app(SupplierWallets::class);
        $deposit = $wallets->deposit($connection, ['amount' => 50000, 'request_id' => (string) Str::uuid()]);
        $this->assertSame('unknown', $deposit->status);
        $this->assertStringContainsString('migrasi payment runtime', $deposit->details['message']);
        $this->actingAs($connection->store->owner)->post('/stores/'.$connection->store_id.'/supplier-deposits/'.$deposit->id.'/check')->assertSessionHasErrors('supplier');
        $this->assertStringContainsString('belum tercatat', $deposit->fresh()->details['message']);
        Http::assertSentCount(2);
    }
}
