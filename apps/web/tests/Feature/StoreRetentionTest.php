<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerOrder;
use App\Jobs\ProcessDigiflazzCallback;
use App\Jobs\SendCustomerNotice;
use App\Mail\StoreMessageMail;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\DiscountVoucher;
use App\Models\OrderAttempt;
use App\Models\PanelNotification;
use App\Models\PlanOption;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerDelivery;
use App\Services\CustomerWallet;
use App\Services\StoreRetention;
use App\Services\SubscriptionService;
use App\Services\Vouchers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StoreRetentionTest extends TestCase
{
    use RefreshDatabase;

    private bool $managerReady = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 1)->startOfDay());
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'platform.store_purge_enabled' => false,
            'services.bot_manager.url' => 'http://127.0.0.1:3099', 'services.bot_manager.token' => str_repeat('t', 40), 'cache.default' => 'array']);
        Queue::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:3099/*' => fn () => Http::response(['status' => $this->managerReady ? 'SUCCESS' : 'FAILED'], $this->managerReady ? 200 : 503)]);
    }

    private function store(?User $owner = null, array $data = []): Store
    {
        $owner ??= User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'phone' => '628'.random_int(1000000000, 9999999999), 'hanshop_user_id' => 'HD'.Str::random(6), 'phone_verified_at' => now(), 'email_verified_at' => now()]);

        return Store::create([...['owner_id' => $owner->id, 'name' => 'Retensi Uji', 'slug' => 'uji-'.Str::lower(Str::random(8)), 'template' => 'aurora', 'category' => 'web', 'plan' => 'orbit', 'status' => 'expired', 'starts_at' => now()->subDays(60), 'expires_at' => now()->subDays(30)], ...$data]);
    }

    private function customer(Store $store, int $balance = 0): Customer
    {
        return Customer::create(['store_id' => $store->id, 'phone' => '6281234567890', 'email' => 'customer@example.test', 'name' => 'Pelanggan Tes', 'phone_verified_at' => now(), 'balance' => $balance]);
    }

    private function order(Store $store, array $data = []): CustomerOrder
    {
        return CustomerOrder::create([...['store_id' => $store->id, 'number' => 'DT'.Str::ulid(), 'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'channel' => 'web', 'purpose' => 'product', 'status' => 'success', 'payment_status' => 'paid', 'gateway' => 'midtrans', 'phone' => '6281234567890', 'destination' => '123456', 'snapshot' => ['name' => 'Produk Tes'], 'amount' => 10000, 'total' => 10000, 'expires_at' => now()->subDays(31)], ...$data]);
    }

    public function test_boundaries_and_preview_do_not_delete_or_notify(): void
    {
        $store = $this->store(null, ['expires_at' => now()->subDays(7)->addSecond(), 'status' => 'grace']);
        $this->assertTrue($store->acceptsOrders());
        $this->assertSame('scheduled', app(StoreRetention::class)->summary($store)['state']);
        $store->update(['expires_at' => now()->subDays(7)]);
        $this->assertFalse($store->acceptsOrders());
        $store->update(['expires_at' => now()->subDays(30)->addSecond()]);
        $this->assertSame('scheduled', app(StoreRetention::class)->summary($store)['state']);
        $store->update(['expires_at' => now()->subDays(30)]);
        $this->assertSame('ready', app(StoreRetention::class)->summary($store)['state']);
        $this->artisan('digitaltoko:retention', ['--store' => $store->id, '--preview' => true])->assertSuccessful();
        $this->assertNull($store->fresh()->retention_checked_at);
        $this->assertSame(0, PanelNotification::count());
        Http::assertNothingSent();
        $this->assertModelExists($store);
    }

    public function test_positive_and_negative_wallets_hold_and_never_offset_each_other(): void
    {
        $store = $this->store();
        $this->customer($store, 10000);
        Customer::create(['store_id' => $store->id, 'phone' => '6282222222222', 'name' => 'Minus', 'balance' => -10000]);
        $summary = app(StoreRetention::class)->review($store);
        $this->assertSame('hold', $summary['state']);
        $this->assertSame(10000, $summary['obligations']['balance']);
        $this->assertSame(10000, $summary['obligations']['debt']);
        config(['platform.store_purge_enabled' => true]);
        $this->assertFalse(app(StoreRetention::class)->purge($store));
        $this->assertNull($store->fresh()->purge_started_at);
        Http::assertNothingSent();
    }

    public function test_running_order_guest_settlement_refund_and_platform_invoice_hold(): void
    {
        $store = $this->store();
        $this->order($store, ['status' => 'pending']);
        $this->order($store, ['status' => 'failed']);
        $this->order($store, ['status' => 'failed', 'customer_id' => $this->customer($store)->id]);
        SubscriptionInvoice::create(['store_id' => $store->id, 'owner_id' => $store->owner_id, 'snapshot' => ['price' => 50000], 'purpose' => 'renew']);
        $summary = app(StoreRetention::class)->review($store);
        $this->assertSame('hold', $summary['state']);
        foreach (['orders', 'guests', 'refunds', 'subscriptions'] as $key) {
            $this->assertSame(1, $summary['obligations'][$key]);
        }
        $this->assertSame('pending', CustomerOrder::where('store_id', $store->id)->where('status', 'pending')->first()->status);
    }

    public function test_suspension_requires_admin_review_and_draft_has_no_deletion_clock(): void
    {
        $suspended = $this->store(null, ['admin_suspended_at' => now()]);
        $this->assertSame('hold', app(StoreRetention::class)->summary($suspended)['state']);
        $draft = $this->store(null, ['status' => 'draft', 'starts_at' => null, 'expires_at' => null]);
        $this->assertSame('none', app(StoreRetention::class)->summary($draft)['state']);
        config(['platform.store_purge_enabled' => true]);
        $this->assertFalse(app(StoreRetention::class)->purge($suspended));
        $this->assertFalse(app(StoreRetention::class)->purge($draft));
    }

    public function test_review_notices_are_deduplicated_scoped_and_include_only_business_contact(): void
    {
        $store = $this->store();
        $other = $this->store($store->owner);
        $this->customer($store, 15000);
        $this->customer($other, 5000);
        $admin = User::factory()->create(['role' => 'admin']);
        app(StoreRetention::class)->review($store);
        app(StoreRetention::class)->review($store->fresh());
        $this->assertSame(1, CustomerNotice::count());
        $notice = CustomerNotice::first();
        $this->assertSame($store->id, $notice->store_id);
        $this->assertStringContainsString('15.000', $notice->message);
        $this->assertStringContainsString($store->owner->phone, $notice->message);
        $this->assertSame(2, PanelNotification::count());
        $this->assertSame(1, PanelNotification::where('user_id', $admin->id)->count());
        Queue::assertPushed(SendCustomerNotice::class, 1);
    }

    public function test_expiry_reminders_have_distinct_stages_and_no_repeated_daily_spam(): void
    {
        $store = $this->store(null, ['expires_at' => now()->subDays(6)]);
        $this->customer($store, 10000);
        app(StoreRetention::class)->review($store);
        app(StoreRetention::class)->review($store->fresh());
        $this->assertSame(1, CustomerNotice::count());
        $this->travel(15)->days();
        app(StoreRetention::class)->review($store->fresh());
        $this->assertSame(2, CustomerNotice::count());
        $this->travel(8)->days();
        app(StoreRetention::class)->review($store->fresh());
        $this->assertSame(3, CustomerNotice::count());
    }

    public function test_renewal_clears_retention_cancels_old_notices_and_keeps_wallet_and_license(): void
    {
        $store = $this->store();
        $customer = $this->customer($store, 12000);
        app(CustomerCommerce::class)->settings($store)->update(['custom_licensed_at' => now()->subMonths(2)]);
        app(StoreRetention::class)->review($store);
        CustomerNotice::first()->update(['wa_sent_at' => now()]);
        CustomerNotice::create(['event' => 'retention:'.$store->id.':'.$store->expires_at->timestamp.':day21:unsent', 'store_id' => $store->id, 'phone' => $customer->phone, 'message' => 'Old warning']);
        $plan = PlanOption::create(['name' => 'Renewal', 'category' => 'web', 'plan' => 'orbit', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $voucher = DiscountVoucher::factory()->create(['type' => 'percent', 'value' => 10000]);
        $invoice = app(SubscriptionService::class)->checkout($store, $plan, $voucher->code, (string) Str::uuid());
        $this->assertSame('applied', $invoice->status);
        $this->assertSame('active', $store->fresh()->status);
        $this->assertTrue($store->fresh()->expires_at->equalTo(now()->addDays(30)));
        $this->assertNull($store->fresh()->retention_state);
        $this->assertSame(12000, $customer->fresh()->balance);
        $this->assertNotNull(app(CustomerCommerce::class)->settings($store)->custom_licensed_at);
        $this->assertSame(0, CustomerNotice::where('event', 'like', 'retention:%')->count());
        $this->assertSame(1, CustomerNotice::where('event', 'like', 'resumed:%')->count());
        config(['platform.store_purge_enabled' => true]);
        $this->assertFalse(app(StoreRetention::class)->purge($store));
        $this->assertModelExists($store);
    }

    public function test_automatic_purge_is_disabled_by_default_and_evaluation_keeps_records(): void
    {
        $store = $this->store();
        $this->artisan('digitaltoko:retention', ['--store' => $store->id])->assertSuccessful();
        $this->assertSame('ready', $store->fresh()->retention_state);
        $this->artisan('digitaltoko:retention', ['--store' => $store->id, '--apply' => true])->assertFailed();
        Http::assertNothingSent();
        $this->assertModelExists($store);
    }

    public function test_stale_ready_preview_is_rechecked_before_any_deletion(): void
    {
        $store = $this->store();
        $this->assertSame('ready', app(StoreRetention::class)->review($store)['state']);
        $this->customer($store, 5000);
        config(['platform.store_purge_enabled' => true]);
        $this->assertFalse(app(StoreRetention::class)->purge($store));
        $this->assertModelExists($store);
        Http::assertNothingSent();
    }

    public function test_failed_worker_cleanup_keeps_records_and_blocks_renewal_until_retry(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        Storage::disk('local')->put('storefront/'.$store->id.'/test.png', 'fixture');
        config(['platform.store_purge_enabled' => true]);
        $this->managerReady = false;
        try {
            app(StoreRetention::class)->purge($store);
            $this->fail('Expected cleanup failure');
        } catch (ValidationException) {
            $this->assertSame('error', $store->fresh()->retention_state);
        }
        $this->assertModelExists($customer);
        Storage::disk('local')->assertExists('storefront/'.$store->id.'/test.png');
        $plan = PlanOption::create(['name' => 'Renewal', 'category' => 'web', 'plan' => 'orbit', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        try {
            app(SubscriptionService::class)->checkout($store->fresh(), $plan);
            $this->fail('Purge must prevent renewal');
        } catch (ValidationException) {
            $this->assertSame(0, SubscriptionInvoice::count());
        }
        $this->managerReady = true;
        $this->assertTrue(app(StoreRetention::class)->purge($store));
        $this->assertModelMissing($store);
    }

    public function test_wallet_callbacks_cannot_mutate_store_after_purge_started(): void
    {
        $store = $this->store(null, ['status' => 'purging', 'purge_started_at' => now(), 'retention_state' => 'error']);
        $customer = $this->customer($store);
        try {
            DB::transaction(fn () => app(CustomerWallet::class)->apply($customer, 5000, 'test-refund', 'refund'));
            $this->fail('Expected purge guard');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertSame(0, $customer->fresh()->balance);
    }

    public function test_purge_removes_only_one_store_files_history_jobs_and_keeps_platform_quota(): void
    {
        $store = $this->store();
        $other = $this->store($store->owner, ['expires_at' => now()->addDays(30), 'status' => 'active']);
        $customer = $this->customer($store);
        $otherCustomer = $this->customer($other, 8000);
        $order = $this->order($store, ['customer_id' => $customer->id]);
        $otherOrder = $this->order($other, ['customer_id' => $otherCustomer->id]);
        $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'TEST', 'name' => 'Test', 'service' => 'Game', 'category' => 'Demo']);
        DB::table('customer_ledgers')->insert(['id' => (string) Str::uuid(), 'store_id' => $store->id, 'customer_id' => $customer->id, 'event' => 'settled', 'amount' => 10000, 'balance_after' => 0, 'reason' => 'refund', 'created_at' => now(), 'updated_at' => now()]);
        $attempt = OrderAttempt::create(['order_id' => $order->id, 'reference' => 'DT'.Str::ulid(), 'position' => 1, 'source' => 'digiflazz', 'cost' => 10000, 'status' => 'success', 'request_snapshot' => ['sku' => 'TEST']]);
        $notice = CustomerNotice::create(['event' => 'order:'.$order->id.':success', 'store_id' => $store->id, 'order_id' => $order->id, 'phone' => $customer->phone, 'message' => 'Result']);
        $voucher = DiscountVoucher::factory()->create(['usage_limit' => 1]);
        $invoice = SubscriptionInvoice::create(['store_id' => $store->id, 'owner_id' => $store->owner_id, 'snapshot' => ['price' => 0], 'purpose' => 'renew', 'status' => 'applied']);
        app(Vouchers::class)->redeem(['id' => $voucher->id, 'discount' => 10000], 'subscription', $invoice->id);
        $this->assertSame(1, app(Vouchers::class)->used($voucher));
        foreach ([$store, $other] as $target) {
            foreach (['storefront', 'articles'] as $folder) {
                Storage::disk('local')->put($folder.'/'.$target->id.'/test.png', 'fixture');
            }
        }
        Storage::disk('local')->put('ktp/owner.png', 'private');
        Cache::put('customer-challenge:test', ['payload' => 'test'], 300);
        Cache::put('store-otp-keys:'.$store->id, ['customer-challenge:test'], 360);
        Cache::put('supplier:shared:test', 'shared account cache', 120);
        foreach ([new ProcessCustomerOrder($order->id), new ProcessCustomerOrder($otherOrder->id), new SendCustomerNotice($notice->id), new ProcessDigiflazzCallback($attempt->id, ['status' => 'Sukses'])] as $job) {
            $command = $job instanceof ProcessDigiflazzCallback ? Crypt::encrypt(serialize($job)) : serialize($job);
            DB::table('jobs')->insert(['queue' => 'default', 'payload' => json_encode(['data' => ['commandName' => $job::class, 'command' => $command]]), 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
        }
        config(['platform.store_purge_enabled' => true]);
        $this->assertTrue(app(StoreRetention::class)->purge($store));
        $this->assertModelMissing($store);
        $this->assertModelMissing($customer);
        $this->assertModelMissing($order);
        $this->assertModelMissing($attempt);
        $this->assertModelMissing($product);
        $this->assertModelMissing($invoice);
        $this->assertModelExists($other);
        $this->assertModelExists($otherOrder);
        $this->assertSame(8000, $otherCustomer->fresh()->balance);
        $this->assertModelExists($store->owner);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('voucher_redemptions')->count());
        $this->assertSame(1, app(Vouchers::class)->used($voucher->fresh()));
        $this->assertNull(Cache::get('customer-challenge:test'));
        $this->assertSame('shared account cache', Cache::get('supplier:shared:test'));
        Storage::disk('local')->assertMissing('storefront/'.$store->id.'/test.png');
        Storage::disk('local')->assertMissing('articles/'.$store->id.'/test.png');
        Storage::disk('local')->assertExists('storefront/'.$other->id.'/test.png');
        Storage::disk('local')->assertExists('ktp/owner.png');
        Http::assertSentCount(1);
        $replacement = $this->store($other->owner, ['slug' => $store->slug]);
        $this->assertNotSame($store->id, $replacement->id);
        $this->assertNull(app(CustomerCommerce::class)->settings($replacement)->custom_licensed_at);
    }

    public function test_owner_staff_admin_visibility_and_cross_owner_isolation(): void
    {
        $store = $this->store();
        $this->customer($store, 5000);
        $this->actingAs($store->owner)->get('/stores/'.$store->id.'/subscription')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('retention.state', 'hold')->where('retention.obligations.balance', 5000)->missing('retention.inventory'));
        $other = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved']);
        $this->actingAs($other)->get('/stores/'.$store->id.'/subscription')->assertForbidden();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'staff', 'email' => 'staff@example.test', 'password' => 'test-password', 'active' => true, 'permissions' => ['subscription.view']]);
        $this->actingAs($staff, 'staff')->get('/stores/'.$store->id.'/subscription')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('retention.state', 'hold'));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')->get('/admin/stores/'.$store->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('retention.state', 'hold')->where('retention.inventory.customers', 1));
    }

    public function test_file_cleanup_failure_keeps_records_and_is_reported_for_retry(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $disk = Storage::disk('local');
        $disk->put('storefront/'.$store->id.'/test.png', 'fixture');
        $failingDisk = \Mockery::mock($disk);
        $failingDisk->shouldReceive('deleteDirectory')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('local')->andReturn($failingDisk);
        config(['platform.store_purge_enabled' => true]);
        try {
            app(StoreRetention::class)->purge($store);
            $this->fail('Expected storage failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Store files could not be removed.', $error->getMessage());
        }
        $this->assertModelExists($customer);
        $this->assertSame('error', $store->fresh()->retention_state);
        $this->assertSame('purging', $store->fresh()->status);
        $this->assertTrue($disk->exists('storefront/'.$store->id.'/test.png'));
    }

    public function test_custom_queue_requires_explicit_cleanup_instead_of_leaving_job_payloads(): void
    {
        $store = $this->store();
        config(['queue.default' => 'redis', 'platform.store_purge_enabled' => true]);
        $summary = app(StoreRetention::class)->summary($store);
        $this->assertSame('hold', $summary['state']);
        $this->assertContains('Antrean khusus memerlukan pembersihan manual sebelum penghapusan', $summary['reasons']);
        $this->assertFalse(app(StoreRetention::class)->purge($store));
        Http::assertNothingSent();
    }

    public function test_retention_email_uses_platform_after_expiry_without_sending_otp_from_platform(): void
    {
        $store = $this->store();
        $this->customer($store, 5000);
        app(StoreRetention::class)->review($store);
        config(['mail.default' => 'smtp']);
        Mail::fake();
        app(CustomerDelivery::class)->send(CustomerNotice::first());
        Mail::assertSent(StoreMessageMail::class, 1);
        $this->assertNotNull(CustomerNotice::first()->email_sent_at);
        app(CustomerDelivery::class)->send(CustomerNotice::first());
        Mail::assertSent(StoreMessageMail::class, 1);
        Http::assertNothingSent();
    }
}
