<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PanelNotification;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\StoreRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class ManualStoreDeletionTest extends TestCase
{
    use RefreshDatabase;

    private bool $managerReady = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Storage::fake('local');
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array', 'platform.store_purge_enabled' => false,
            'services.bot_manager.url' => 'http://127.0.0.1:3099', 'services.bot_manager.token' => str_repeat('t', 40)]);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:3099/*' => fn () => Http::response(['status' => $this->managerReady ? 'SUCCESS' : 'FAILED'], $this->managerReady ? 200 : 503)]);
    }

    private function store(array $data = []): Store
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.Str::random(8), 'phone_verified_at' => now(), 'email_verified_at' => now()]);

        return Store::create([...['owner_id' => $owner->id, 'name' => 'Toko baru', 'slug' => 'baru-'.Str::lower(Str::random(8)), 'category' => 'web', 'plan' => 'orbit', 'template' => 'aurora', 'status' => 'draft'], ...$data]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function confirmation(Store $store): array
    {
        return ['confirmation' => $store->slug, 'preview_token' => app(StoreRetention::class)->summary($store->fresh(), true)['manual_token']];
    }

    private function customer(Store $store, int $balance): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => 'Saldo uji', 'phone' => '6281234567890', 'balance' => $balance]);
    }

    public function test_new_unsubscribed_store_can_request_then_admin_delete_without_bot_manager_or_automatic_flag(): void
    {
        $store = $this->store();
        $owner = $store->owner;
        $admin = $this->admin();
        $this->actingAs($owner)->post('/stores/'.$store->id.'/closure', ['closing' => true, 'finalize' => true])->assertSessionHasNoErrors();
        $store->refresh();
        $this->assertSame('suspended_manual', $store->status);
        $this->assertNotNull($store->closure_requested_at);
        $this->assertSame('requested', $store->retention_state);
        $this->assertNull($store->expires_at);
        $this->assertNotNull(PanelNotification::where('user_id', $admin->id)->where('title', 'Permohonan penghapusan toko')->first());
        $this->actingAs($admin, 'web')->get('/admin/stores/'.$store->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('retention.manual_request', true)->where('retention.can_delete_manually', true)->where('retention.state', 'requested')->where('retention.automatic_enabled', false));
        $this->delete('/admin/stores/'.$store->id, $this->confirmation($store))->assertRedirect(route('admin.stores.index'))->assertSessionHasNoErrors();
        $this->assertModelMissing($store);
        $this->assertModelExists($owner);
        $this->assertSame(0, SubscriptionInvoice::count());
        Http::assertNothingSent();
    }

    public function test_legacy_closed_store_without_timestamp_or_expiry_is_eligible_for_admin_approval(): void
    {
        $store = $this->store(['status' => 'suspended_manual']);
        $this->assertNull($store->closure_requested_at);
        $summary = app(StoreRetention::class)->summary($store, true);
        $this->assertTrue($summary['can_delete_manually']);
        $this->assertSame('requested', $summary['state']);
        $this->actingAs($this->admin())->delete('/admin/stores/'.$store->id, $this->confirmation($store))->assertSessionHasNoErrors();
        $this->assertModelMissing($store);
    }

    public function test_manual_deletion_does_not_wait_for_paid_subscription_expiry_but_requires_worker_cleanup(): void
    {
        $store = $this->store(['status' => 'suspended_manual', 'closure_requested_at' => now(), 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(29), 'bot_phone' => '6281234567890']);
        $this->actingAs($this->admin())->delete('/admin/stores/'.$store->id, $this->confirmation($store))->assertSessionHasNoErrors();
        $this->assertModelMissing($store);
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:3099/stores/'.$store->id.'/disconnect');
    }

    public function test_owner_staff_and_guest_cannot_approve_permanent_deletion(): void
    {
        $store = $this->store(['status' => 'suspended_manual']);
        $data = $this->confirmation($store);
        $this->delete('/admin/stores/'.$store->id, $data)->assertRedirect('/admin/login');
        $this->actingAs($store->owner)->delete('/admin/stores/'.$store->id, $data)->assertForbidden();
        $other = User::factory()->create(['role' => 'owner']);
        $this->actingAs($other)->delete('/admin/stores/'.$store->id, $data)->assertForbidden();
        $this->app['auth']->guard('web')->logout();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'staff', 'email' => 'staff@example.test', 'password' => 'test-password-123', 'active' => true, 'permissions' => ['web.manage']]);
        $this->actingAs($staff, 'staff')->delete('/admin/stores/'.$store->id, $data)->assertRedirect('/admin/login');
        $this->assertModelExists($store);
        Http::assertNothingSent();
    }

    public function test_admin_cannot_delete_an_open_draft_even_with_a_valid_preview(): void
    {
        $store = $this->store();
        $this->actingAs($this->admin())->delete('/admin/stores/'.$store->id, $this->confirmation($store))->assertSessionHasErrors('retention');
        $this->assertNull($store->fresh()->purge_started_at);
        $this->assertModelExists($store);
        Http::assertNothingSent();
    }

    public function test_wrong_confirmation_and_stale_preview_are_rejected(): void
    {
        $store = $this->store(['status' => 'suspended_manual']);
        $data = $this->confirmation($store);
        $this->actingAs($this->admin())->delete('/admin/stores/'.$store->id, [...$data, 'confirmation' => 'wrong-store'])->assertSessionHasErrors('confirmation');
        $store->update(['expires_at' => now()->addDays(30)]);
        $this->delete('/admin/stores/'.$store->id, $data)->assertSessionHasErrors('retention');
        $this->assertNull($store->fresh()->purge_started_at);
        $this->assertModelExists($store);
        Http::assertNothingSent();
    }

    public function test_outstanding_balance_and_invoice_block_manual_deletion_even_after_preview(): void
    {
        $store = $this->store(['status' => 'suspended_manual']);
        $data = $this->confirmation($store);
        $this->customer($store, 10000);
        SubscriptionInvoice::create(['store_id' => $store->id, 'owner_id' => $store->owner_id, 'status' => 'pending', 'snapshot' => ['price' => 50000], 'purpose' => 'initial']);
        $this->actingAs($this->admin())->delete('/admin/stores/'.$store->id, $data)->assertSessionHasErrors('retention');
        $summary = app(StoreRetention::class)->summary($store->fresh());
        $this->assertSame('hold', $summary['state']);
        $this->assertFalse($summary['can_delete_manually']);
        $this->assertSame(10000, $summary['obligations']['balance']);
        $this->assertSame(1, $summary['obligations']['subscriptions']);
        Http::assertNothingSent();
    }

    public function test_manual_requests_are_never_removed_by_automatic_retention_even_after_30_days(): void
    {
        $store = $this->store(['status' => 'suspended_manual', 'closure_requested_at' => now(), 'expires_at' => now()->subDays(31)]);
        config(['platform.store_purge_enabled' => true]);
        $this->artisan('digitaltoko:retention', ['--store' => $store->id, '--apply' => true])->assertSuccessful();
        $this->assertSame('requested', $store->fresh()->retention_state);
        $this->assertModelExists($store);
        Http::assertNothingSent();
    }

    public function test_failed_cleanup_can_be_retried_from_admin_with_automatic_deletion_disabled(): void
    {
        $store = $this->store(['status' => 'suspended_manual', 'bot_phone' => '6281234567890']);
        Storage::disk('local')->put('storefront/'.$store->id.'/test.png', 'fixture');
        $this->managerReady = false;
        $admin = $this->admin();
        $this->actingAs($admin)->delete('/admin/stores/'.$store->id, $this->confirmation($store))->assertSessionHasErrors('connection');
        $store->refresh();
        $this->assertSame('purging', $store->status);
        $this->assertSame('manual', $store->purge_origin);
        $this->assertSame('error', $store->retention_state);
        Storage::disk('local')->assertExists('storefront/'.$store->id.'/test.png');
        $this->get('/admin/stores/'.$store->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('retention.can_delete_manually', true)->where('retention.manual_request', true));
        $this->managerReady = true;
        $this->delete('/admin/stores/'.$store->id, $this->confirmation($store))->assertRedirect(route('admin.stores.index'))->assertSessionHasNoErrors();
        $this->assertModelMissing($store);
        Storage::disk('local')->assertMissing('storefront/'.$store->id.'/test.png');
        $this->assertFalse((bool) config('platform.store_purge_enabled'));
    }

    public function test_manual_cleanup_reclaims_slug_but_preserves_other_store_and_owner(): void
    {
        $store = $this->store(['status' => 'suspended_manual']);
        $other = Store::create(['owner_id' => $store->owner_id, 'name' => 'Tetap aktif', 'slug' => 'aktif-'.Str::lower(Str::random(8)), 'category' => 'web', 'plan' => 'orbit', 'template' => 'aurora', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
        $this->customer($other, 5000);
        $this->actingAs($this->admin())->delete('/admin/stores/'.$store->id, $this->confirmation($store))->assertSessionHasNoErrors();
        $this->assertModelExists($other);
        $this->assertModelExists($store->owner);
        $this->assertSame(5000, Customer::where('store_id', $other->id)->value('balance'));
        $replacement = Store::create(['owner_id' => $other->owner_id, 'name' => 'Toko pengganti', 'slug' => $store->slug, 'category' => 'web', 'plan' => 'orbit', 'template' => 'aurora', 'status' => 'draft']);
        $this->assertNotSame($store->id, $replacement->id);
    }

    public function test_finalization_is_idempotent_and_does_not_spam_admin(): void
    {
        $store = $this->store();
        $admin = $this->admin();
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/closure', ['closing' => true, 'finalize' => true])->assertSessionHasNoErrors();
        $requestedAt = $store->fresh()->closure_requested_at;
        $this->post('/stores/'.$store->id.'/closure', ['closing' => true, 'finalize' => true])->assertSessionHasNoErrors();
        $this->assertTrue($requestedAt->equalTo($store->fresh()->closure_requested_at));
        $this->assertSame(1, PanelNotification::where('user_id', $admin->id)->where('title', 'Permohonan penghapusan toko')->count());
        $this->post('/stores/'.$store->id.'/closure', ['closing' => false])->assertSessionHasErrors('commerce');
    }
}
