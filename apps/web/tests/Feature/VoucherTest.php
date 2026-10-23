<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerOrder;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\DiscountVoucher;
use App\Models\PlanOption;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\CommerceModules;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerPayments;
use App\Services\SubscriptionService;
use App\Services\Vouchers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VoucherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false]);
        Queue::fake([ProcessCustomerOrder::class]);
        Http::preventStrayRequests();
    }

    private function store(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.Str::random(6), 'phone_verified_at' => now(), 'email_verified_at' => now()])->id, 'name' => 'Toko Tes', 'slug' => 'tes-'.Str::lower(Str::random(8)), 'template' => 'aurora', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
    }

    private function product(Store $s): CatalogProduct
    {
        $p = CatalogProduct::create(['store_id' => $s->id, 'code' => Str::upper(Str::random(8)), 'name' => 'Akun Tes', 'kind' => 'premium', 'service' => 'Aplikasi', 'category' => 'Canva', 'sale_price' => 15000, 'active' => true]);
        ProductMapping::create(['store_id' => $s->id, 'product_id' => $p->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        PremiumStock::create(['store_id' => $s->id, 'product_id' => $p->id, 'payload' => 'test-only credential', 'cost' => 10000]);

        return $p;
    }

    private function voucher(Store $s, array $extra = []): DiscountVoucher
    {
        return DiscountVoucher::factory()->create([...['store_id' => $s->id, 'scope_key' => $s->id], ...$extra]);
    }

    private function customer(Store $s): Customer
    {
        return Customer::create(['store_id' => $s->id, 'name' => 'Customer', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'balance' => 100000]);
    }

    private function input(CatalogProduct $p, DiscountVoucher $v, array $extra = []): array
    {
        return [...['purpose' => 'product', 'product_id' => $p->id, 'phone' => '6281234567890', 'destination' => '6281234567890', 'payment' => 'wallet', 'expected_price' => 15000, 'idempotency_key' => (string) Str::uuid(), 'voucher_code' => $v->code], ...$extra];
    }

    private function invalid(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_discount_wallet_refund_and_idempotency_use_actual_paid_price(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $c = $this->customer($s);
        $v = $this->voucher($s, ['value' => 7000, 'usage_limit' => 1]);
        $input = $this->input($p, $v);
        $commerce = app(CustomerCommerce::class);
        $order = $commerce->begin($s, $input, $c);
        $this->assertSame(8000, $order->amount);
        $this->assertSame(92000, $c->fresh()->balance);
        $this->assertSame(15000, $order->snapshot['subtotal']);
        $this->assertSame($order->id, $commerce->begin($s, $input, $c)->id);
        $this->assertSame(1, app(Vouchers::class)->used($v));
        $this->invalid(fn () => $commerce->begin($s, $this->input($p, $v), $c));
        $commerce->fail($order, 'Gagal pemasok');
        $commerce->fail($order->fresh(), 'Gagal pemasok');
        $this->assertSame(100000, $c->fresh()->balance);
        $this->assertSame(1, app(Vouchers::class)->used($v));
    }

    public function test_store_funded_discount_does_not_reduce_supplier_ceiling_or_block_stock_delivery(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $c = $this->customer($s);
        $v = $this->voucher($s, ['value' => 7000]);
        $order = app(CustomerCommerce::class)->begin($s, $this->input($p, $v), $c);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(8000, $order->fresh()->amount);
        $this->assertSame(10000, $order->attempts()->first()->cost);
    }

    public function test_multi_target_union_and_cross_store_isolation(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $p->load('taxonomyCategory');
        $other = $this->store();
        $otherProduct = $this->product($other);
        $service = CatalogService::where('store_id', $s->id)->firstOrFail();
        foreach ([['services' => [$service->id]], ['categories' => [$p->category_id]], ['products' => [$p->id]], ['services' => ['missing'], 'products' => [$p->id]]] as $targets) {
            $v = $this->voucher($s, ['all_targets' => false, 'targets' => $targets]);
            $this->assertSame(14000, app(Vouchers::class)->quote($s, $v->code, 15000, $p)['total']);
            $this->invalid(fn () => app(Vouchers::class)->quote($other, $v->code, 15000, $otherProduct));
        }
        $v = $this->voucher($s, ['all_targets' => false, 'targets' => ['products' => [$otherProduct->id]]]);
        $this->invalid(fn () => app(Vouchers::class)->quote($s, $v->code, 15000, $p));
    }

    public function test_percentage_cap_schedule_minimum_and_zero_payment_rejection(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $v = $this->voucher($s, ['type' => 'percent', 'value' => 1250, 'max_discount' => 1500]);
        $this->assertSame(1500, app(Vouchers::class)->quote($s, strtolower($v->code), 15000, $p)['discount']);
        foreach ([['active' => false], ['starts_at' => now()->addMinute()], ['ends_at' => now()->subMinute()], ['min_purchase' => 20000], ['value' => 10000, 'max_discount' => null]] as $bad) {
            $v = $this->voucher($s, [...['type' => 'percent', 'value' => 1000], ...$bad]);
            $this->invalid(fn () => app(Vouchers::class)->quote($s, $v->code, 15000, $p));
        }
    }

    public function test_cancelled_unpaid_invoice_releases_quota_but_sandbox_usage_is_separate(): void
    {
        $s = $this->store();
        $s->update(['sandbox' => true]);
        $p = $this->product($s);
        $c = $this->customer($s);
        $v = $this->voucher($s, ['usage_limit' => 1]);
        $order = app(CustomerCommerce::class)->begin($s, $this->input($p, $v, ['payment' => 'gateway']), $c);
        $this->assertSame(1, app(Vouchers::class)->used($v, true));
        $this->assertSame(0, app(Vouchers::class)->used($v));
        app(CustomerPayments::class)->cancel($order);
        $this->assertSame(0, app(Vouchers::class)->used($v, true));
        $second = app(CustomerCommerce::class)->begin($s, $this->input($p, $v, ['payment' => 'gateway']), $c);
        $this->assertNotSame($order->id, $second->id);
    }

    public function test_topup_cannot_use_vouchers_and_failed_order_creation_does_not_use_quota(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $c = $this->customer($s);
        $v = $this->voucher($s);
        $this->invalid(fn () => app(CustomerCommerce::class)->begin($s, $this->input($p, $v, ['purpose' => 'topup', 'payment' => 'gateway', 'amount' => 10000, 'expected_price' => 10000]), $c));
        $c->update(['balance' => 0]);
        $this->invalid(fn () => app(CustomerCommerce::class)->begin($s, $this->input($p, $v), $c));
        $this->assertDatabaseCount('voucher_redemptions', 0);
    }

    public function test_web_preview_and_checkout_apply_voucher_after_flash_sale_without_using_quota_on_preview(): void
    {
        $store = $this->store();
        $store->update(['sandbox' => true]);
        app(CustomerCommerce::class)->settings($store)->update(['allow_guest' => true]);
        $product = $this->product($store);
        $store->update(['branding' => ['design' => ['flash_product_ids' => [$product->id], 'flash_discounts' => [$product->id => ['type' => 'fixed', 'value' => 1000]], 'sections' => [['key' => 'flash_sale', 'enabled' => true]]]]]);
        $voucher = $this->voucher($store, ['type' => 'percent', 'value' => 1000]);
        $url = 'http://'.$store->slug.'.localhost';
        $this->postJson($url.'/checkout/voucher', ['product_id' => $product->id, 'voucher_code' => $voucher->code])->assertOk()->assertJsonPath('voucher.subtotal', 14000)->assertJsonPath('voucher.discount', 1400)->assertJsonPath('voucher.total', 12600);
        $this->assertDatabaseCount('voucher_redemptions', 0);
        $this->post($url.'/checkout', $this->input($product, $voucher, ['payment' => 'gateway', 'expected_price' => 14000]))->assertSessionHasNoErrors()->assertRedirect();
        $order = CustomerOrder::firstOrFail();
        $this->assertSame(12600, $order->amount);
        $this->assertSame(14000, $order->snapshot['subtotal']);
        $this->assertSame(12600, $order->refund_amount);
        $voucher->update(['value' => 9000, 'active' => false]);
        $this->assertSame(1400, $order->fresh()->snapshot['voucher']['discount']);
        $this->postJson($url.'/checkout/voucher', ['product_id' => $product->id, 'voucher_code' => $voucher->code])->assertUnprocessable();
    }

    private function data(array $extra = []): array
    {
        return [...['code' => 'PROMO10', 'name' => 'Promo', 'active' => true, 'all_targets' => false, 'type' => 'percent', 'value' => 10, 'min_purchase' => 0, 'targets' => ['services' => [], 'categories' => [], 'products' => [], 'plans' => []]], ...$extra];
    }

    public function test_owner_staff_and_admin_management_are_scoped_and_validate_foreign_targets(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $foreign = $this->product($this->store());
        $this->actingAs($s->owner)->get('/stores/'.$s->id.'/vouchers')->assertOk();
        $this->post('/stores/'.$s->id.'/vouchers', $this->data(['targets' => ['services' => [], 'categories' => [], 'products' => [$p->id], 'plans' => []]]))->assertRedirect();
        $v = DiscountVoucher::firstOrFail();
        $this->assertSame(1000, $v->value);
        $this->post('/stores/'.$s->id.'/vouchers', $this->data(['code' => 'OTHER', 'targets' => ['services' => [], 'categories' => [], 'products' => [$foreign->id], 'plans' => []]]))->assertUnprocessable();
        $other = $this->store();
        $this->patch('/stores/'.$other->id.'/vouchers/'.$v->id, $this->data())->assertForbidden();
        $staff = StoreStaff::create(['store_id' => $s->id, 'name' => 'Staff', 'username' => 'teststaff', 'email' => 'staff@example.test', 'password' => bcrypt('test-password'), 'active' => true, 'permissions' => ['vouchers.view']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->get('/stores/'.$s->id.'/vouchers')->assertOk();
        $this->delete('/stores/'.$s->id.'/vouchers/'.$v->id)->assertForbidden();
        auth('staff')->logout();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')->post('/admin/vouchers', $this->data(['all_targets' => true]))->assertRedirect();
        $this->get('/admin/vouchers')->assertOk();
        $platform = DiscountVoucher::whereNull('store_id')->firstOrFail();
        $this->assertSame('platform', $platform->scope_key);
        $this->delete('/admin/vouchers/'.$platform->id)->assertRedirect();
        $this->assertSoftDeleted($platform);
    }

    public function test_platform_discounts_bill_net_subscription_and_module_without_debiting_hanshop(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('x', 40)]);
        Http::fake(['https://hanshop.test/*' => fn ($r) => Http::response(['status' => 'SUCCESS', 'data' => ['invoice' => ['invoice_id' => $r['invoice_id'], 'id_user' => $r['id_user'], 'amount' => $r['amount'], 'total' => $r['amount'] + 123, 'id_payment' => 'PAY'.$r['invoice_id'], 'status' => 'PENDING', 'expires_at' => now()->addMinutes(15)->toIso8601String(), 'qr_url' => 'https://example.test/qr', 'paid_at' => null]]])]);
        $s = $this->store();
        $plan = PlanOption::create(['name' => 'Web Tes', 'category' => 'web', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $v = DiscountVoucher::factory()->create(['all_targets' => false, 'targets' => ['plans' => [(string) $plan->id]], 'value' => 5000]);
        $invoice = app(SubscriptionService::class)->checkout($s, $plan, $v->code);
        $this->assertSame(45000, $invoice->snapshot['price']);
        $this->assertSame(50000, $invoice->snapshot['base_price']);
        $this->assertSame($invoice->id, app(SubscriptionService::class)->checkout($s, $plan, $v->code)->id);
        DB::table('commerce_options')->updateOrInsert(['id' => 'custom_qris_price'], ['value' => '20000']);
        $moduleVoucher = DiscountVoucher::factory()->create(['all_targets' => false, 'targets' => ['services' => ['custom_qris']], 'value' => 2000]);
        $module = app(CommerceModules::class)->buy($s, $moduleVoucher->code);
        $this->assertSame(18000, $module->amount);
        $this->assertSame([45000, 45000, 18000], Http::recorded()->map(fn ($pair) => (int) $pair[0]['amount'])->values()->all());
    }

    public function test_free_subscription_requires_request_key_and_activates_without_gateway_or_replay_extension(): void
    {
        $store = $this->store();
        $store->update(['status' => 'draft', 'category' => 'web', 'starts_at' => null, 'expires_at' => null]);
        $plan = PlanOption::create(['name' => 'Gratis Web', 'category' => 'web', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 7, 'active' => true]);
        $voucher = DiscountVoucher::factory()->create(['type' => 'percent', 'value' => 10000, 'usage_limit' => 1]);
        $service = app(SubscriptionService::class);
        $this->invalid(fn () => $service->checkout($store, $plan, $voucher->code));
        $this->assertDatabaseCount('subscription_invoices', 0);
        $key = (string) Str::uuid();
        $invoice = $service->checkout($store, $plan, $voucher->code, $key);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(0, $invoice->total);
        $this->assertNull($invoice->qr_url);
        $this->assertNull($invoice->provider_id);
        $this->assertSame('provisioning', $store->fresh()->status);
        $this->assertNull($store->fresh()->expires_at);
        $service->activate($store);
        $expiry = $store->fresh()->expires_at;
        $this->assertSame('active', $store->fresh()->status);
        $this->assertFalse($store->fresh()->is_trial);
        $this->assertSame($invoice->id, $service->checkout($store, $plan, $voucher->code, $key)->id);
        $service->sync($invoice);
        $this->assertTrue($expiry->equalTo($store->fresh()->expires_at));
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertDatabaseCount('voucher_redemptions', 1);
        $this->invalid(fn () => $service->checkout($store, $plan, $voucher->code, (string) Str::uuid()));
        Http::assertNothingSent();
    }

    public function test_free_renewal_is_idempotent_and_free_downgrade_waits_for_next_period(): void
    {
        $store = $this->store();
        $store->update(['category' => 'web', 'plan' => 'galaxy', 'starts_at' => now()->subDay()]);
        $base = $store->fresh()->expires_at;
        $plan = PlanOption::create(['name' => 'Web', 'category' => 'web', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $voucher = DiscountVoucher::factory()->create(['value' => 50000]);
        $key = (string) Str::uuid();
        $service = app(SubscriptionService::class);
        $invoice = $service->checkout($store, $plan, $voucher->code, $key);
        $this->assertSame('applied', $invoice->status);
        $this->assertTrue($base->addDays(30)->equalTo($store->fresh()->expires_at));
        $service->checkout($store, $plan, $voucher->code, $key);
        $this->assertTrue($base->addDays(30)->equalTo($store->fresh()->expires_at));
        $store->update(['category' => 'full']);
        $lower = PlanOption::create(['name' => 'Web Dasar', 'category' => 'web', 'plan' => 'galaxy', 'price' => 25000, 'duration_days' => 7, 'active' => true]);
        $this->invalid(fn () => $service->checkout($store, $lower, $voucher->code, $key));
        $down = $service->checkout($store, $lower, $voucher->code, (string) Str::uuid());
        $this->assertSame('paid', $down->status);
        $this->assertSame('galaxy', $store->fresh()->plan);
        $expiry = $store->fresh()->expires_at;
        $this->travelTo($expiry);
        $service->tick($store);
        $this->assertSame('web', $store->fresh()->category);
        $this->assertSame('galaxy', $store->fresh()->plan);
        $this->assertTrue($expiry->addDays(7)->equalTo($store->fresh()->expires_at));
        Http::assertNothingSent();
    }

    public function test_free_module_voucher_grants_license_without_gateway(): void
    {
        $store = $this->store();
        DB::table('commerce_options')->updateOrInsert(['id' => 'custom_qris_price'], ['value' => '20000']);
        $voucher = DiscountVoucher::factory()->create(['type' => 'percent', 'value' => 10000, 'usage_limit' => 1]);
        $invoice = app(CommerceModules::class)->buy($store, $voucher->code);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(0, $invoice->total);
        $this->assertNull($invoice->qr_url);
        $this->assertNotNull(app(CustomerCommerce::class)->settings($store)->custom_licensed_at);
        $this->invalid(fn () => app(CommerceModules::class)->buy($store, $voucher->code));
        $this->assertDatabaseCount('module_purchases', 1);
        Http::assertNothingSent();
    }

    public function test_owner_checkout_accepts_free_voucher_and_repeated_http_request_only_renews_once(): void
    {
        $store = $this->store();
        $store->update(['category' => 'web', 'starts_at' => now()->subDay()]);
        $expiry = $store->fresh()->expires_at;
        $plan = PlanOption::create(['name' => 'Web Promo', 'category' => 'web', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 7, 'active' => true]);
        $voucher = DiscountVoucher::factory()->create(['type' => 'percent', 'value' => 10000, 'usage_limit' => 1]);
        $input = ['plan_id' => $plan->id, 'voucher_code' => $voucher->code, 'request_id' => (string) Str::uuid()];
        $url = '/stores/'.$store->id.'/subscription/checkout';
        $this->actingAs($store->owner)->post($url, $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->post($url, $input)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($expiry->addDays(7)->equalTo($store->fresh()->expires_at));
        $this->assertDatabaseCount('subscription_invoices', 1);
        Http::assertNothingSent();
    }
}
