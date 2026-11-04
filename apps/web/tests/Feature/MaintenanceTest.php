<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\CustomerOrder;
use App\Models\MaintenanceWindow;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerInquiryService;
use App\Services\StoreMaintenance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class MaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'cache.default' => 'array', 'inertia.ssr.enabled' => false]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function store(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['verification_status' => 'approved'])->id,
            'name' => 'Toko Uji', 'slug' => 'shop-'.Str::lower(Str::random(6)), 'template' => 'aurora', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function pause(?Store $store = null, ?string $ends = null): void
    {
        MaintenanceWindow::updateOrCreate(['scope' => app(StoreMaintenance::class)->scope($store)], [
            'store_id' => $store?->id, 'enabled' => true, 'message' => 'Peningkatan layanan toko.', 'ends_at' => $ends]);
    }

    private function product(Store $store): CatalogProduct
    {
        $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'PREMIUM', 'name' => 'Akun Uji', 'kind' => 'premium', 'service' => 'Aplikasi', 'category' => 'Premium', 'sale_price' => 15000, 'active' => true]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        PremiumStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'payload' => 'SIMULATED-CREDENTIAL', 'cost' => 10000]);

        return $product;
    }

    private function input(CatalogProduct $product): array
    {
        return ['purpose' => 'product', 'product_id' => $product->id, 'phone' => '6281234567890', 'destination' => '6281234567890', 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()];
    }

    public function test_admin_global_maintenance_affects_all_stores_but_not_landing_or_panel(): void
    {
        $a = $this->store();
        $b = $this->store();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/maintenance')->assertInertia(fn (Page $page) => $page->component('Maintenance/Settings')->where('role', 'admin'));
        $this->patch('/admin/maintenance', ['enabled' => true, 'message' => 'Maintenance platform.', 'ends_at' => now()->addHour()->toIso8601String()])->assertSessionHasNoErrors();
        foreach ([$a, $b] as $store) {
            $this->get('http://'.$store->slug.'.localhost/')->assertOk();
            $this->post('http://'.$store->slug.'.localhost/checkout')->assertRedirect('/maintenance');
            $this->get('http://'.$store->slug.'.localhost/maintenance')->assertOk()->assertSee('Maintenance platform.')->assertSee('countdown', false);
        }
        $this->get('http://localhost/')->assertOk();
        $this->get('http://localhost/admin/maintenance')->assertOk();
        $this->assertTrue($a->fresh()->canConnectBot());
    }

    public function test_owner_can_only_pause_own_store_and_cannot_override_global_window(): void
    {
        $a = $this->store();
        $b = $this->store();
        $url = '/stores/'.$a->id.'/maintenance';
        $this->actingAs($a->owner)->patch($url, ['enabled' => true, 'message' => 'Toko A maintenance.', 'ends_at' => now()->addMinutes(20)->toIso8601String()])->assertSessionHasNoErrors();
        $this->patch('/stores/'.$b->id.'/maintenance', ['enabled' => true])->assertForbidden();
        $this->patch('/admin/maintenance', ['enabled' => true])->assertForbidden();
        $this->get('http://'.$b->slug.'.localhost/')->assertOk();
        $this->get('http://localhost'.$url)->assertOk();
        $this->pause();
        $this->patch($url, ['enabled' => false])->assertSessionHasNoErrors();
        $this->assertSame('platform', app(StoreMaintenance::class)->active($a)['scope']);
        $staff = StoreStaff::create(['store_id' => $a->id, 'name' => 'Staff', 'username' => 'staff', 'email' => 'staff@example.test', 'password' => 'dummy-password-123', 'active' => true, 'permissions' => ['web.manage']]);
        Auth::guard('web')->logout();
        $this->actingAs($staff, 'staff')->patch($url, ['enabled' => false])->assertForbidden();
    }

    public function test_deadline_expires_without_scheduler_and_overlapping_windows_do_not_open_early(): void
    {
        $store = $this->store();
        $this->pause(null, now()->addMinute()->toIso8601String());
        $this->pause($store, now()->addMinutes(2)->toIso8601String());
        $this->assertSame(now()->addMinutes(2)->toIso8601String(), app(StoreMaintenance::class)->active($store)['ends_at']);
        $this->travel(61)->seconds();
        $this->assertSame('store', app(StoreMaintenance::class)->active($store)['scope']);
        $this->travel(60)->seconds();
        $this->assertNull(app(StoreMaintenance::class)->active($store));
        $this->get('http://'.$store->slug.'.localhost/maintenance')->assertRedirect('/');
        $this->get('http://'.$store->slug.'.localhost/')->assertOk();
        $this->pause($store);
        $this->pause(null, now()->addMinute()->toIso8601String());
        $this->assertNull(app(StoreMaintenance::class)->active($store)['ends_at']);
    }

    public function test_maintenance_blocks_web_inertia_and_json_new_activity_without_creating_orders(): void
    {
        $store = $this->store();
        $this->pause($store);
        $base = 'http://'.$store->slug.'.localhost';
        $this->get($base.'/')->assertOk();
        $this->post($base.'/checkout')->assertRedirect('/maintenance');
        $this->withHeaders(['X-Inertia' => 'true'])->post($base.'/checkout', [])->assertStatus(409)->assertHeader('X-Inertia-Location', '/maintenance');
        $this->flushHeaders();
        $this->postJson($base.'/checkout/inquiry', [])->assertStatus(503)->assertJsonPath('maintenance.scope', 'store');
        $this->post($base.'/account/otp', ['intent' => 'register'])->assertRedirect('/maintenance');
        $this->get($base.'/cek-pesanan')->assertOk();
        $this->get($base.'/account/login')->assertOk();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_maintenance_keeps_web_and_bot_catalogues_available(): void
    {
        $store = $this->store();
        $product = $this->product($store);
        $token = str_repeat('c', 64);
        $store->update(['worker_token_hash' => hash('sha256', $token)]);
        $this->pause($store);
        $base = 'http://'.$store->slug.'.localhost';
        foreach (['aurora', 'nebula', 'atlas'] as $template) {
            $store->update(['template' => $template]);
            $this->get($base.'/')->assertOk();
            $this->get($base.'/kategori/'.$product->category_id)->assertOk()
                ->assertInertia(fn (Page $page) => $page->component('Storefront/Category')->where('products.0.id', $product->id));
        }
        $this->getJson($base.'/search?q=Akun')->assertOk();
        $this->get($base.'/checkout?product='.$product->id)->assertOk()->assertInertia(fn (Page $page) => $page
            ->component('Storefront/Checkout')->where('storeMaintenance.store_slug', $store->slug)
            ->where('storeMaintenance.message', 'Peningkatan layanan toko.')->where('storeMaintenance.server_now', now()->toIso8601String()));
        $this->get($base.'/artikel')->assertOk();
        $this->get($base.'/leaderboard')->assertOk();
        $this->withToken($token)->getJson('http://localhost/api/internal/bot/products')->assertOk()->assertJsonPath('data.items.0.id', $product->id);
        $this->withToken($token)->getJson('http://localhost/api/internal/bot/catalog?view=services')->assertOk()->assertJsonPath('data.items.0.name', 'Aplikasi');
        $this->withToken($token)->getJson('http://localhost/api/internal/bot/catalog?view=categories&service=Aplikasi')->assertOk()->assertJsonPath('data.items.0.name', 'Premium');
        $this->withToken($token)->getJson('http://localhost/api/internal/bot/catalog?view=products&service=Aplikasi&category=Premium')->assertOk()->assertJsonPath('data.items.0.id', $product->id);
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('customers', 0);
        Http::assertNothingSent();
    }

    public function test_service_and_bot_cannot_create_orders_or_inquiries_during_maintenance(): void
    {
        $store = $this->store();
        $product = $this->product($store);
        $token = str_repeat('m', 64);
        $store->update(['worker_token_hash' => hash('sha256', $token)]);
        $this->pause();
        foreach (['buy', 'topup', 'inquiry', 'quote'] as $action) {
            $this->withToken($token)->postJson('/api/internal/bot/commerce', ['phone' => '6281234567890', 'action' => $action, 'code' => $product->code, 'product_id' => $product->id, 'destination' => '6281234567890', 'amount' => 50000, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('maintenance');
        }
        $this->withToken($token)->getJson('/api/internal/bot/access')->assertOk()->assertJsonPath('data.can_connect', true)->assertJsonPath('data.maintenance.scope', 'platform');
        foreach (['balance', 'status'] as $action) {
            $this->withToken($token)->postJson('/api/internal/bot/commerce', ['phone' => '6281234567890', 'action' => $action])->assertOk();
        }
        $this->assertDatabaseCount('customers', 0);
        foreach (['order', 'inquiry'] as $action) {
            try {
                $action === 'order' ? app(CustomerCommerce::class)->begin($store, $this->input($product), null) : app(CustomerInquiryService::class)->quote($store, $product->id, '123456', '6281234567890', 'unique-key');
                $this->fail('Maintenance gate was bypassed');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('maintenance', $error->errors());
            }
        }
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('customer_inquiries', 0);
    }

    public function test_existing_payment_webhook_and_premium_fulfillment_continue_during_maintenance(): void
    {
        $store = $this->store();
        $product = $this->product($store);
        $engine = app(CustomerCommerce::class);
        $engine->settings($store)->update(['gateway' => 'midtrans', 'credentials' => ['server_key' => 'test-server']]);
        $order = $engine->begin($store, $this->input($product), null);
        $this->pause();
        $data = ['order_id' => $order->number, 'status_code' => '200', 'gross_amount' => (string) $order->total, 'transaction_status' => 'settlement', 'currency' => 'IDR', 'transaction_time' => now()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s')];
        $data['signature_key'] = hash('sha512', $data['order_id'].$data['status_code'].$data['gross_amount'].'test-server');
        Http::fake(['*' => Http::response($data)]);
        $this->postJson('/api/commerce/midtrans/'.$store->id, $data)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('success', $order->fresh()->status, $order->fresh()->message);
        $url = 'http://'.$store->slug.'.localhost/orders/'.$order->id.'?access='.$engine->token($order);
        $this->get($url)->assertOk();
        $this->assertSame(1, CustomerOrder::count());
    }

    public function test_deadline_and_message_are_validated_before_saving(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->patch('/admin/maintenance', ['enabled' => true, 'message' => str_repeat('x', 1001), 'ends_at' => now()->subMinute()->toIso8601String()])
            ->assertSessionHasErrors(['message', 'ends_at']);
        $this->assertDatabaseCount('maintenance_windows', 0);
    }
}
