<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerOrder;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerOrder;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerInquiryService;
use App\Services\CustomerPayments;
use App\Services\StoreEnvironment;
use App\Services\SupplierCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class StoreSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ProcessCustomerOrder::class]);
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false, 'suppliers.cache_store' => 'array', 'cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    private function shop(bool $sandbox = true): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.random_int(1000, 99999)])->id,
            'name' => 'Sandbox shop', 'slug' => 'test-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy', 'template' => 'aurora', 'status' => 'active', 'expires_at' => now()->addDays(30), 'sandbox' => $sandbox]);
    }

    private function customer(Store $store): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => 'Tester', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'balance' => 200000, 'sandbox_balance' => 100000]);
    }

    private function product(Store $store, string $source = 'stock', string $mode = 'prepaid'): CatalogProduct
    {
        $product = CatalogProduct::create(['store_id' => $store->id, 'code' => Str::upper(Str::random(10)), 'name' => 'Test product', 'kind' => $source === 'stock' ? 'premium' : ($mode === 'pasca' ? 'postpaid' : 'ppob'), 'service' => 'Pulsa', 'category' => 'XL', 'sale_price' => 15000, 'active' => true, 'auto_update' => false]);
        $item = null;
        if ($source !== 'stock') {
            $connection = SupplierConnection::firstOrCreate(['store_id' => $store->id, 'kind' => $source], ['enabled' => true, 'credentials' => $source === 'digiflazz' ? ['username' => 'buyer', 'api_key' => 'secret-prod', 'development_key' => 'secret-dev'] : null]);
            $item = SupplierProduct::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'sku' => $mode === 'pasca' ? 'pln' : 'xld10', 'mode' => $mode, 'name' => 'Test source', 'kind' => $product->kind, 'service' => 'Pulsa', 'category' => 'XL', 'cost' => 10000, 'available' => true, 'quantity' => null, 'observed_at' => now()]);
        }
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => $source, 'source_key' => $item ? 'item:'.$item->id : 'stock', 'supplier_product_id' => $item?->id, 'active' => true]);

        return $product;
    }

    private function order(Store $store, CatalogProduct $product, ?Customer $customer = null, array $extra = []): CustomerOrder
    {
        app(CustomerCommerce::class)->settings($store)->update(['allow_guest' => true]);

        return app(CustomerCommerce::class)->begin($store, [...['product_id' => $product->id, 'phone' => '6281234567890', 'destination' => '087800001230', 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], ...$extra], $customer, 'bot');
    }

    public function test_only_owner_can_change_mode_and_other_shops_are_unchanged(): void
    {
        $shop = $this->shop(false);
        $other = $this->shop(false);
        $url = '/owner/stores/'.$shop->id.'/environment';
        $this->actingAs($other->owner)->patch($url, ['sandbox' => true])->assertNotFound();
        $this->actingAs($shop->owner)->from('/owner/stores/'.$shop->id.'/manage')->patch($url, ['sandbox' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($shop->fresh()->sandbox);
        $this->assertFalse($other->fresh()->sandbox);
        $this->assertSame('active', $shop->fresh()->status);
    }

    public function test_mode_switch_blocks_running_orders_and_does_not_reset_balances(): void
    {
        $shop = $this->shop();
        $customer = $this->customer($shop);
        $order = $this->order($shop, $this->product($shop), $customer);
        try {
            app(StoreEnvironment::class)->change($shop, false);
            $this->fail('Running orders must block environment changes.');
        } catch (ValidationException) {
            $this->assertTrue($shop->fresh()->sandbox);
        }
        app(CustomerPayments::class)->cancel($order);
        app(StoreEnvironment::class)->change($shop, false);
        $this->assertFalse($shop->fresh()->sandbox);
        $this->assertTrue($order->fresh()->is_sandbox);
        $this->assertSame(200000, $customer->fresh()->balance);
        $this->assertSame(100000, $customer->fresh()->sandbox_balance);
    }

    public function test_premium_simulation_never_consumes_real_stock_or_wallet(): void
    {
        $shop = $this->shop();
        $customer = $this->customer($shop);
        $product = $this->product($shop);
        $stock = PremiumStock::create(['store_id' => $shop->id, 'product_id' => $product->id, 'payload' => 'REAL SECRET', 'cost' => 10000]);
        $order = $this->order($shop, $product, $customer, ['payment' => 'wallet']);
        app(CustomerFulfillment::class)->run($order);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertStringNotContainsString('REAL SECRET', $order->fresh()->result);
        $this->assertSame('ready', $stock->fresh()->status);
        $this->assertSame(200000, $customer->fresh()->balance);
        $this->assertSame(85000, $customer->fresh()->sandbox_balance);
        $this->assertSame(1, CustomerLedger::where('is_sandbox', true)->count());
        Http::assertNothingSent();
    }

    public function test_sandbox_stock_order_works_without_any_real_credentials(): void
    {
        $shop = $this->shop();
        $order = $this->order($shop, $this->product($shop));
        $this->assertSame('sandbox', $order->gateway);
        $this->assertNull($order->payment_credentials);
        $url = 'http://'.$shop->slug.'.localhost/orders/'.$order->id.'/simulate?access='.app(CustomerCommerce::class)->token($order);
        $this->post($url)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($url)->assertSessionHasNoErrors();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(0, PremiumStock::count());
        $this->assertDatabaseCount('commerce_receipts', 1);
        Http::assertNothingSent();
    }

    public function test_simulation_requires_order_access_and_cannot_settle_production_orders(): void
    {
        $shop = $this->shop();
        $order = $this->order($shop, $this->product($shop));
        $url = 'http://'.$shop->slug.'.localhost/orders/'.$order->id.'/simulate';
        $this->post($url)->assertNotFound();
        $order->update(['is_sandbox' => false]);
        $this->post($url.'?access='.app(CustomerCommerce::class)->token($order))->assertForbidden();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_hanshop_order_is_simulated_without_external_requests(): void
    {
        $shop = $this->shop();
        $order = $this->order($shop, $this->product($shop, 'hanshop'), $this->customer($shop), ['payment' => 'wallet']);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertStringContainsString('SIMULASI HANSHOP', $order->fresh()->result);
        Http::assertNothingSent();
    }

    public function test_digiflazz_uses_only_development_snapshot_and_testing_flag(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop, 'digiflazz');
        $order = $this->order($shop, $product, $this->customer($shop), ['payment' => 'wallet']);
        $attempt = $order->attempts()->firstOrFail();
        $this->assertSame('secret-dev', $attempt->request_snapshot['credentials']['api_key']);
        $this->assertStringNotContainsString('secret-prod', json_encode($attempt->request_snapshot));
        Http::fake(['api.digiflazz.com/*' => function ($request) {
            $this->assertTrue($request['testing']);
            $this->assertSame(md5('buyer'.'secret-dev'.$request['ref_id']), $request['sign']);

            return Http::response(['data' => ['ref_id' => $request['ref_id'], 'customer_no' => $request['customer_no'], 'buyer_sku_code' => $request['buyer_sku_code'], 'status' => 'Sukses', 'rc' => '00', 'price' => 10000, 'sn' => 'TEST-SN']]);
        }]);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_missing_development_key_never_falls_back_to_production(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop, 'digiflazz');
        $connection = SupplierConnection::where('store_id', $shop->id)->firstOrFail();
        $connection->update(['credentials' => ['username' => 'buyer', 'api_key' => 'secret-prod']]);
        $this->expectException(ValidationException::class);
        try {
            $this->order($shop, $product, $this->customer($shop), ['payment' => 'wallet']);
        } finally {
            $this->assertSame(0, CustomerOrder::count());
            Http::assertNothingSent();
        }
    }

    public function test_development_connection_preserves_production_key_and_uses_official_test_catalog(): void
    {
        $shop = $this->shop();
        $this->product($shop, 'digiflazz');
        $this->actingAs($shop->owner)->post('/stores/'.$shop->id.'/sources', ['kind' => 'digiflazz', 'username' => '', 'api_key' => 'replacement-dev', 'enabled' => true])->assertSessionHasNoErrors();
        $connection = SupplierConnection::where('store_id', $shop->id)->firstOrFail();
        $this->assertSame('secret-prod', $connection->credentials['api_key']);
        $this->assertSame('replacement-dev', $connection->credentials['development_key']);
        app(SupplierCatalog::class)->sync($connection);
        $this->assertDatabaseHas('supplier_products', ['connection_id' => $connection->id, 'sku' => 'xld10', 'available' => true]);
        app(StoreEnvironment::class)->change($shop, false);
        $this->assertDatabaseHas('supplier_products', ['connection_id' => $connection->id, 'sku' => 'xld10', 'available' => false]);
        $this->assertNull($connection->fresh()->synced_at);
        Http::assertNothingSent();
    }

    public function test_sandbox_topup_and_refund_only_mutate_test_balance(): void
    {
        $shop = $this->shop();
        $customer = $this->customer($shop);
        $engine = app(CustomerCommerce::class);
        $topup = $engine->begin($shop, ['purpose' => 'topup', 'amount' => 50000, 'phone' => $customer->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $customer);
        $engine->paid($topup, 'sandbox:'.$topup->id, $topup->total, now());
        $engine->paid($topup, 'sandbox:'.$topup->id, $topup->total, now());
        $this->assertSame(150000, $customer->fresh()->sandbox_balance);
        $order = $this->order($shop, $this->product($shop), $customer, ['payment' => 'wallet']);
        DB::transaction(fn () => $engine->fail($order, 'Simulasi gagal'));
        DB::transaction(fn () => $engine->fail($order->fresh(), 'Simulasi gagal'));
        $this->assertSame(150000, $customer->fresh()->sandbox_balance);
        $this->assertSame(200000, $customer->fresh()->balance);
        $this->assertSame(3, CustomerLedger::where('is_sandbox', true)->count());
    }

    public function test_midtrans_sandbox_is_selected_only_for_explicit_test_credentials(): void
    {
        $shop = $this->shop();
        $setting = app(CustomerCommerce::class)->settings($shop);
        $setting->update(['gateway' => 'midtrans', 'sandbox' => false, 'credentials' => ['server_key' => 'Mid-server-production']]);
        $environment = app(StoreEnvironment::class);
        $this->assertSame('sandbox', $environment->gateway($shop, $setting));
        $setting->update(['sandbox' => true]);
        $this->assertSame('midtrans', $environment->gateway($shop, $setting));
        $setting->update(['credentials' => ['server_key' => 'SB-Mid-server-test']]);
        $order = $this->order($shop, $this->product($shop));
        $this->assertSame('midtrans', $order->gateway);
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['status_code' => '404'])]);
        app(CustomerPayments::class)->start($order);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.sandbox.midtrans.com/'));
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://api.midtrans.com/'));
    }

    public function test_account_exposes_test_balance_and_test_history_only(): void
    {
        $shop = $this->shop();
        $customer = $this->customer($shop);
        $this->order($shop, $this->product($shop), $customer);
        $this->actingAs($customer, 'customer')->get('http://'.$shop->slug.'.localhost/account')
            ->assertInertia(fn (Page $page) => $page->where('customer.balance', 100000)->where('storeSandbox', true)->has('items', 1));
    }

    public function test_inquiry_and_payment_keep_development_credentials_and_test_flag(): void
    {
        $shop = $this->shop();
        $customer = $this->customer($shop);
        $product = $this->product($shop, 'digiflazz', 'pasca');
        $commands = [];
        Http::fake(['api.digiflazz.com/*' => function ($request) use (&$commands) {
            $this->assertTrue($request['testing']);
            $this->assertSame(md5('buyer'.'secret-dev'.$request['ref_id']), $request['sign']);
            $commands[] = $request['commands'];

            return Http::response(['data' => ['ref_id' => $request['ref_id'], 'customer_no' => $request['customer_no'], 'buyer_sku_code' => $request['buyer_sku_code'], 'status' => 'Sukses', 'rc' => '00', 'price' => 10000, 'customer_name' => 'Simulasi', 'sn' => 'TEST-PLN']]);
        }]);
        $inquiry = app(CustomerInquiryService::class)->quote($shop, $product->id, '530000000001', $customer->phone, (string) Str::uuid());
        $this->assertTrue($inquiry->data['sandbox']);
        try {
            app(StoreEnvironment::class)->change($shop, false);
            $this->fail('A live inquiry must prevent environment switching.');
        } catch (ValidationException) {
            $this->assertTrue($shop->fresh()->sandbox);
        }
        $order = $this->order($shop, $product, $customer, ['payment' => 'wallet', 'destination' => '530000000001', 'inquiry_id' => $inquiry->id]);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(['inq-pasca', 'inq-pasca', 'pay-pasca'], $commands);
        $this->assertSame(200000, $customer->fresh()->balance);
    }

    public function test_production_supplier_balance_is_not_reused_as_sandbox_balance(): void
    {
        $shop = $this->shop(false);
        $this->product($shop, 'digiflazz');
        $connection = SupplierConnection::where('store_id', $shop->id)->firstOrFail();
        Http::fake(['api.digiflazz.com/*' => Http::response(['data' => ['deposit' => 234567]])]);
        $catalog = app(SupplierCatalog::class);
        $this->assertSame(234567, $catalog->balance($connection)['amount']);
        app(StoreEnvironment::class)->change($shop, true);
        $connection->refresh()->unsetRelation('store');
        $balance = $catalog->balance($connection);
        $this->assertTrue($balance['simulated']);
        $this->assertSame(1000000, $balance['amount']);
        Http::assertSentCount(1);
    }

    public function test_owner_can_cancel_unpaid_order_from_panel_then_change_environment(): void
    {
        $shop = $this->shop();
        $order = $this->order($shop, $this->product($shop));
        $url = '/stores/'.$shop->id.'/orders/'.$order->id;
        $this->actingAs($shop->owner)->get($url)->assertInertia(fn (Page $page) => $page->where('canCancel', true));
        try {
            app(StoreEnvironment::class)->change($shop, false);
            $this->fail('A running order must block switching.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString($order->number, $error->errors()['commerce'][0]);
        }
        $this->from($url)->post($url, ['action' => 'cancel'])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame('expired', $order->fresh()->status);
        $this->get($url)->assertInertia(fn (Page $page) => $page->where('canCancel', false)->where('order.cancelled', true));
        $this->assertSame($shop->owner_id, $order->fresh()->snapshot['cancelled_by_owner']);
        $this->assertTrue(app(CustomerCommerce::class)->publicData($order->fresh())['cancelled']);
        app(StoreEnvironment::class)->change($shop, false);
        $this->assertFalse($shop->fresh()->sandbox);
        Http::assertNothingSent();
    }

    public function test_owner_cancellation_rejects_staff_other_owners_and_paid_orders(): void
    {
        $shop = $this->shop();
        $order = $this->order($shop, $this->product($shop));
        $url = '/stores/'.$shop->id.'/orders/'.$order->id;
        $other = $this->shop();
        $this->actingAs($other->owner)->post($url, ['action' => 'cancel'])->assertForbidden();
        $staff = StoreStaff::create(['store_id' => $shop->id, 'name' => 'Viewer', 'username' => 'viewer', 'email' => 'viewer@example.test', 'password' => 'long-test-password', 'active' => true, 'permissions' => ['orders.view']]);
        $this->actingAs($staff, 'staff')->post($url, ['action' => 'cancel'])->assertForbidden();
        $this->actingAs($shop->owner, 'web');
        app(CustomerCommerce::class)->paid($order, 'test:'.$order->id, $order->total, now());
        $this->post($url, ['action' => 'cancel'])->assertSessionHasErrors('commerce');
        $this->assertSame('paid', $order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_owner_cancellation_checks_midtrans_and_preserves_order_on_gateway_failure(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop);
        $first = $this->order($shop, $product);
        $second = $this->order($shop, $product);
        foreach ([$first, $second] as $order) {
            $order->update(['gateway' => 'midtrans', 'snapshot' => [...$order->snapshot, 'sandbox' => true], 'payment_credentials' => ['server_key' => 'SB-Mid-server-test']]);
        }
        Http::fake(['api.sandbox.midtrans.com/*' => fn ($request) => str_contains($request->url(), $first->number) ? Http::response(['status_code' => '404']) : Http::response([], 503)]);
        $this->actingAs($shop->owner)->post('/stores/'.$shop->id.'/orders/'.$first->id, ['action' => 'cancel'])->assertSessionHasNoErrors();
        $this->assertSame('expired', $first->fresh()->status);
        $this->post('/stores/'.$shop->id.'/orders/'.$second->id, ['action' => 'cancel'])->assertSessionHasErrors('commerce');
        $this->assertSame('awaiting_payment', $second->fresh()->status);
        $this->assertSame('unpaid', $second->fresh()->payment_status);
        Http::assertSentCount(2);
    }

    private function staleMidtransInvoice(bool $sandbox = true): CustomerOrder
    {
        $shop = $this->shop();
        $order = $this->order($shop, $this->product($shop));
        $order->update(['gateway' => 'midtrans', 'is_sandbox' => false, 'snapshot' => [...$order->snapshot, 'sandbox' => $sandbox], 'payment_credentials' => ['server_key' => 'old-key']]);
        app(CustomerCommerce::class)->settings($shop)->update(['gateway' => 'midtrans', 'sandbox' => $sandbox, 'credentials' => ['server_key' => 'new-key']]);

        return $order;
    }

    public function test_owner_can_retire_uncreated_sandbox_invoice_after_old_key_is_rejected(): void
    {
        $order = $this->staleMidtransInvoice();
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());

            return ($request->header('Authorization')[0] ?? '') === 'Basic '.base64_encode('old-key:')
                ? Http::response([], 401) : Http::response(['status_code' => '404']);
        });
        $this->actingAs($order->store->owner)->post('/stores/'.$order->store_id.'/orders/'.$order->id, ['action' => 'cancel'])->assertSessionHasNoErrors();
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame('sandbox_uncreated_stale_key', $order->fresh()->snapshot['cancellation_recovery']['reason']);
        $this->assertSame('old-key', $order->fresh()->payment_credentials['server_key']);
        Http::assertSentCount(2);
    }

    public function test_recovery_never_applies_to_production_or_invoice_with_provider_qr_or_dispatched_attempt(): void
    {
        foreach (['production', 'provider', 'qr', 'dispatched'] as $scenario) {
            $order = $this->staleMidtransInvoice($scenario !== 'production');
            if ($scenario === 'provider') {
                $order->update(['provider_id' => 'PROVIDER-REF']);
            } elseif ($scenario === 'qr') {
                $order->update(['qr_image' => 'data:image/png;base64,TEST']);
            } elseif ($scenario === 'dispatched') {
                $order->attempts()->firstOrFail()->update(['last_checked_at' => now()]);
            }
            Http::fake(fn () => Http::response([], 401));
            $this->actingAs($order->store->owner)->post('/stores/'.$order->store_id.'/orders/'.$order->id, ['action' => 'cancel'])->assertSessionHasErrors('commerce');
            $this->assertSame('awaiting_payment', $order->fresh()->status, $scenario);
            $this->assertArrayNotHasKey('cancellation_recovery', $order->fresh()->snapshot);
            Http::assertSentCount(1);
        }
    }

    public function test_recovery_rejects_invalid_new_key_or_uncertain_gateway_results(): void
    {
        foreach ([401, 503, 200] as $status) {
            $order = $this->staleMidtransInvoice();
            Http::fake(function ($request) use ($status) {
                return ($request->header('Authorization')[0] ?? '') === 'Basic '.base64_encode('old-key:')
                    ? Http::response([], 401) : Http::response(['status_code' => $status === 200 ? '200' : '404', 'transaction_status' => 'pending'], $status);
            });
            $this->actingAs($order->store->owner)->post('/stores/'.$order->store_id.'/orders/'.$order->id, ['action' => 'cancel'])->assertSessionHasErrors('commerce');
            $this->assertSame('awaiting_payment', $order->fresh()->status);
            Http::assertSentCount(2);
        }
    }

    public function test_customer_cannot_use_owner_recovery_for_stale_sandbox_credentials(): void
    {
        $order = $this->staleMidtransInvoice();
        Http::fake(fn () => Http::response([], 401));
        try {
            app(CustomerPayments::class)->cancel($order);
            $this->fail('Customer must not bypass unavailable credentials.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Server key', $error->errors()['commerce'][0]);
        }
        $this->assertSame('awaiting_payment', $order->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_midtrans_sandbox_order_can_complete_by_local_simulation_without_gateway_calls(): void
    {
        $shop = $this->shop();
        $order = $this->order($shop, $this->product($shop));
        $order->update(['gateway' => 'midtrans', 'snapshot' => [...$order->snapshot, 'sandbox' => true], 'payment_credentials' => ['server_key' => 'sandbox-test-only']]);
        $data = app(CustomerCommerce::class)->publicData($order->refresh());
        $this->assertTrue($data['can_simulate_payment']);
        $this->assertTrue($data['midtrans_sandbox']);
        $url = 'http://'.$shop->slug.'.localhost/orders/'.$order->id.'/simulate?access='.app(CustomerCommerce::class)->token($order);
        $this->post($url)->assertSessionHasNoErrors();
        $this->post($url)->assertSessionHasNoErrors();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertTrue(app(CustomerCommerce::class)->publicData($order->fresh())['simulated_payment']);
        $this->assertFalse(app(CustomerCommerce::class)->publicData($order->fresh())['can_simulate_payment']);
        $this->assertSame(0, PremiumStock::count());
        $this->assertDatabaseCount('commerce_receipts', 1);
        app(CustomerPayments::class)->refresh($order->fresh());
        Http::assertNothingSent();
    }

    public function test_local_simulation_of_midtrans_sandbox_topup_credits_test_balance_once(): void
    {
        $shop = $this->shop();
        $customer = $this->customer($shop);
        $order = app(CustomerCommerce::class)->begin($shop, ['purpose' => 'topup', 'amount' => 50000, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $customer);
        $order->update(['gateway' => 'midtrans', 'snapshot' => [...$order->snapshot, 'sandbox' => true]]);
        app(CustomerPayments::class)->simulate($order);
        app(CustomerPayments::class)->simulate($order);
        $this->assertSame(150000, $customer->fresh()->sandbox_balance);
        $this->assertSame(200000, $customer->fresh()->balance);
        $this->assertDatabaseCount('customer_ledgers', 1);
        $this->assertDatabaseCount('commerce_receipts', 1);
        Http::assertNothingSent();
    }

    public function test_local_simulation_rejects_midtrans_production_expired_and_cancelled_invoices(): void
    {
        foreach (['production', 'expired', 'cancelled'] as $scenario) {
            $shop = $this->shop();
            $order = $this->order($shop, $this->product($shop));
            $order->update(['gateway' => 'midtrans', 'snapshot' => [...$order->snapshot, 'sandbox' => $scenario !== 'production']]);
            if ($scenario === 'expired') {
                $order->update(['expires_at' => now()->subSecond()]);
            } elseif ($scenario === 'cancelled') {
                $order->update(['status' => 'expired']);
            }
            $this->assertFalse(app(CustomerPayments::class)->canSimulate($order));
            $url = 'http://'.$shop->slug.'.localhost/orders/'.$order->id.'/simulate?access='.app(CustomerCommerce::class)->token($order);
            $response = $this->post($url);
            if ($scenario === 'production') {
                $response->assertForbidden();
            } else {
                $response->assertSessionHasErrors('commerce');
            }
            $this->assertSame('unpaid', $order->fresh()->payment_status);
            $this->assertArrayNotHasKey('payment_simulation', $order->fresh()->snapshot);
        }
        Http::assertNothingSent();
    }

    public function test_correlated_digiflazz_http_400_whitelist_rejection_fails_and_refunds_correct_wallet(): void
    {
        foreach ([true, false] as $sandbox) {
            $shop = $this->shop($sandbox);
            $customer = $this->customer($shop);
            $order = $this->order($shop, $this->product($shop, 'digiflazz'), $customer, ['payment' => 'wallet']);
            Http::fake(['api.digiflazz.com/*' => function ($request) {
                return Http::response(['data' => ['ref_id' => $request['ref_id'], 'customer_no' => $request['customer_no'], 'buyer_sku_code' => $request['buyer_sku_code'], 'status' => 'Gagal', 'rc' => '45', 'message' => 'IP Anda tidak kami kenali: 8.8.4.4 SECRET-IP']], 400);
            }]);
            app(CustomerFulfillment::class)->run($order);
            $this->assertSame('failed', $order->fresh()->status);
            $attempt = $order->attempts()->firstOrFail();
            $this->assertSame('failed', $attempt->status);
            $this->assertStringContainsString('whitelist Digiflazz mode '.($sandbox ? 'Development' : 'Production'), $attempt->message);
            $this->assertStringContainsString('Tambahkan IP 8.8.4.4', $attempt->message);
            $this->assertStringNotContainsString('SECRET-IP', $attempt->message);
            $this->assertSame(200000, $customer->fresh()->balance);
            $this->assertSame(100000, $customer->fresh()->sandbox_balance);
            $this->assertNotNull($order->fresh()->refunded_at);
            Http::assertSentCount(1);
        }
    }

    public function test_untrusted_or_uncertain_http_error_does_not_fail_or_refund_pending_transaction(): void
    {
        foreach (['wrong_ref', 'wrong_sku', 'wrong_destination', 'unknown_rc', 'pending', 'server_error', 'success_on_error', 'empty'] as $scenario) {
            $shop = $this->shop();
            $customer = $this->customer($shop);
            $order = $this->order($shop, $this->product($shop, 'digiflazz'), $customer, ['payment' => 'wallet']);
            Http::fake(['api.digiflazz.com/*' => function ($request) use ($scenario) {
                $body = ['ref_id' => $request['ref_id'], 'customer_no' => $request['customer_no'], 'buyer_sku_code' => $request['buyer_sku_code'], 'status' => 'Gagal', 'rc' => '45'];
                if ($scenario === 'wrong_ref') {
                    $body['ref_id'] = 'OTHER';
                }
                if ($scenario === 'wrong_sku') {
                    $body['buyer_sku_code'] = 'OTHER';
                }
                if ($scenario === 'wrong_destination') {
                    $body['customer_no'] = 'OTHER';
                }
                if ($scenario === 'unknown_rc') {
                    $body['rc'] = 'UNKNOWN';
                }
                if ($scenario === 'pending') {
                    $body['status'] = 'Pending';
                    $body['rc'] = '03';
                }
                if ($scenario === 'success_on_error') {
                    $body['status'] = 'Sukses';
                    $body['rc'] = '00';
                    $body['price'] = 10000;
                    $body['sn'] = 'DO-NOT-FULFILL';
                }

                return Http::response($scenario === 'empty' ? [] : ['data' => $body], $scenario === 'server_error' ? 503 : 400);
            }]);
            app(CustomerFulfillment::class)->run($order);
            $this->assertSame('pending', $order->fresh()->status, $scenario);
            $this->assertNull($order->fresh()->refunded_at);
            $this->assertSame(85000, $customer->fresh()->sandbox_balance);
        }
    }

    public function test_postpaid_payment_inquiry_http_400_rejection_never_proceeds_to_payment(): void
    {
        $shop = $this->shop();
        $customer = $this->customer($shop);
        $product = $this->product($shop, 'digiflazz', 'pasca');
        $calls = 0;
        Http::fake(['api.digiflazz.com/*' => function ($request) use (&$calls) {
            $calls++;
            $this->assertSame('inq-pasca', $request['commands']);
            $this->assertTrue($request['testing']);

            return Http::response(['data' => ['ref_id' => $request['ref_id'], 'customer_no' => $request['customer_no'], 'buyer_sku_code' => $request['buyer_sku_code'], 'status' => $calls === 1 ? 'Sukses' : 'Gagal', 'rc' => $calls === 1 ? '00' : '45', 'price' => 10000]], $calls === 1 ? 200 : 400);
        }]);
        $inquiry = app(CustomerInquiryService::class)->quote($shop, $product->id, '530000000001', $customer->phone, (string) Str::uuid());
        $order = $this->order($shop, $product, $customer, ['payment' => 'wallet', 'destination' => '530000000001', 'inquiry_id' => $inquiry->id]);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(100000, $customer->fresh()->sandbox_balance);
        $this->assertSame(2, $calls);
    }
}
