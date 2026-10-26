<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StorePaymentSetting;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\StoreSetup;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'cache.default' => 'array', 'inertia.ssr.enabled' => false]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function shop(string $category = 'web'): Store
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'email_verified_at' => now(), 'phone_verified_at' => now()]);

        return Store::create(['owner_id' => $owner->id, 'name' => 'Toko Setup', 'slug' => 'setup-'.Store::count(), 'category' => $category, 'plan' => 'galaxy', 'status' => 'provisioning', 'template' => 'aurora']);
    }

    private function stock(Store $store): CatalogProduct
    {
        $product = CatalogProduct::factory()->create(['store_id' => $store->id]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id]);

        return $product;
    }

    private function item(Store $store, string $key): array
    {
        return collect(app(StoreSetup::class)->summary($store)['items'])->firstWhere('key', $key);
    }

    public function test_paid_web_store_has_actionable_checklist_without_requiring_custom_domain_or_whatsapp(): void
    {
        $store = $this->shop();
        $this->actingAs($store->owner)->get('/stores/'.$store->id.'/subscription')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('setup.total', 4)->where('setup.completed', 1)->where('setup.ready', false)
            ->where('setup.items.0.ready', false)->where('setup.items.0.href', '/stores/'.$store->id.'/sources')
            ->where('setup.items.3.ready', true)->where('setup.items.4.required', false));
        $this->assertTrue(app(SubscriptionService::class)->ready($store));
        $this->assertDatabaseCount('store_payment_settings', 0);
        Http::assertNothingSent();
    }

    public function test_stock_only_store_can_complete_setup_without_any_remote_connection(): void
    {
        $store = $this->shop();
        $product = $this->stock($store);
        StorePaymentSetting::create(['store_id' => $store->id, 'gateway' => 'midtrans', 'sandbox' => false, 'credentials' => ['server_key' => 'private-key']]);
        $summary = app(StoreSetup::class)->summary($store);
        $this->assertTrue($summary['ready']);
        $this->assertSame(4, $summary['completed']);
        $this->assertSame('/stores/'.$store->id.'/stock', $summary['items'][0]['href']);
        $this->assertSame('/stores/'.$store->id.'/stock', app(StoreSetup::class)->summary($store, false, ['stock.manage'])['items'][0]['href']);
        $this->assertStringNotContainsString('private-key', json_encode($summary));
        $product->update(['active' => false]);
        $this->assertFalse($this->item($store, 'catalog')['ready']);
        $product->update(['active' => true, 'sale_price' => 500]);
        $this->assertFalse($this->item($store, 'catalog')['ready']);
        PremiumStock::where('product_id', $product->id)->update(['status' => 'sold']);
        $this->assertFalse($this->item($store, 'source')['ready']);
        Http::assertNothingSent();
    }

    public function test_supplier_readiness_uses_current_mapping_availability_and_resolved_price_without_syncing(): void
    {
        $store = $this->shop();
        $connection = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'digiflazz', 'enabled' => true, 'verified_at' => now()]);
        $source = SupplierProduct::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'sku' => 'D10', 'name' => 'Digi', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'Pulsa', 'cost' => 10000, 'available' => true, 'quantity' => null, 'observed_at' => now()]);
        $product = CatalogProduct::factory()->create(['store_id' => $store->id, 'kind' => 'ppob', 'sale_price' => 500, 'markup' => '10%', 'auto_update' => true]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'supplier_product_id' => $source->id, 'source' => 'digiflazz', 'source_key' => 'item:'.$source->id, 'active' => true]);
        $this->assertTrue($this->item($store, 'catalog')['ready']);
        $source->update(['observed_at' => now()->subHour()]);
        $this->assertFalse($this->item($store, 'catalog')['ready']);
        $source->update(['observed_at' => now(), 'available' => false]);
        $this->assertFalse($this->item($store, 'catalog')['ready']);
        $source->update(['available' => true, 'quantity' => 0]);
        $this->assertFalse($this->item($store, 'catalog')['ready']);
        $source->update(['quantity' => 2]);
        $connection->update(['enabled' => false]);
        $this->assertFalse($this->item($store, 'source')['ready']);
        $this->assertFalse($this->item($store, 'catalog')['ready']);
        $this->assertSame(500, $product->fresh()->sale_price);
        Http::assertNothingSent();
    }

    public function test_other_stores_configuration_never_completes_this_store_and_routes_are_scoped(): void
    {
        $mine = $this->shop();
        $other = $this->shop();
        $this->stock($other);
        StorePaymentSetting::create(['store_id' => $other->id, 'gateway' => 'midtrans', 'sandbox' => false, 'credentials' => ['server_key' => 'secret-other']]);
        $this->assertSame(1, app(StoreSetup::class)->summary($mine)['completed']);
        $this->actingAs($mine->owner)->get('/stores/'.$other->id.'/subscription')->assertForbidden();
        $this->get('/owner/stores/'.$mine->id)->assertOk()->assertInertia(fn (Assert $page) => $page->where('setup.completed', 1));
        Http::assertNothingSent();
    }

    public function test_staff_links_follow_permissions_and_never_offer_domain_controls(): void
    {
        $store = $this->shop();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Operator', 'username' => 'operator', 'email' => 'staff@example.test', 'password' => 'password-test-123', 'active' => true, 'permissions' => ['dashboard.view', 'subscription.view']]);
        $this->actingAs($staff, 'staff')->get('/stores/'.$store->id.'/subscription')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('setup.items.0.href', null)->where('setup.items.1.href', null)->where('setup.items.2.href', null)->where('setup.items.3.href', null)->where('setup.items.4.href', null));
        $staff->update(['permissions' => ['dashboard.view', 'subscription.view', 'pricing.manage', 'bot.manage', 'payments.policy']]);
        $this->get('/staff/'.$store->id.'/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('setup.items.0.href', '/stores/'.$store->id.'/sources')->where('setup.items.2.href', '/stores/'.$store->id.'/payments')
            ->where('setup.items.3.href', null)->where('setup.items.4.href', '/stores/'.$store->id.'/bot'));
    }

    public function test_bot_platform_requires_whatsapp_and_has_no_domain_item(): void
    {
        foreach (['bot', 'full'] as $category) {
            $store = $this->shop($category);
            $this->assertTrue($this->item($store, 'whatsapp')['required']);
            $this->assertFalse($this->item($store, 'whatsapp')['ready']);
            $this->assertFalse(app(SubscriptionService::class)->ready($store));
            Cache::put('bot:'.$store->id.':state', ['status' => 'CONNECTED']);
            $this->assertTrue($this->item($store, 'whatsapp')['ready']);
            $this->assertTrue(app(SubscriptionService::class)->ready($store));
            $keys = array_column(app(StoreSetup::class)->summary($store)['items'], 'key');
            $this->assertSame($category === 'full', in_array('domain', $keys, true));
        }
    }

    public function test_payment_checklist_distinguishes_production_sandbox_and_custom_license(): void
    {
        $store = $this->shop();
        $setting = StorePaymentSetting::create(['store_id' => $store->id, 'gateway' => 'midtrans', 'sandbox' => true, 'credentials' => ['server_key' => 'sandbox-key']]);
        $this->assertFalse($this->item($store, 'payment')['ready']);
        $store->update(['sandbox' => true]);
        $this->assertTrue($this->item($store, 'payment')['ready']);
        $setting->update(['credentials' => null]);
        $this->assertTrue($this->item($store, 'payment')['ready']);
        $store->update(['sandbox' => false]);
        $setting->update(['gateway' => 'custom', 'credentials' => ['qris_payload' => 'configured', 'forward_token' => 'private-token']]);
        $this->assertFalse($this->item($store, 'payment')['ready']);
        $setting->update(['custom_licensed_at' => now()]);
        $this->assertTrue($this->item($store, 'payment')['ready']);
        $setting->update(['closing' => true]);
        $this->assertFalse($this->item($store, 'payment')['ready']);
    }

    public function test_pending_custom_domain_does_not_block_builtin_address_or_expose_verification_secret(): void
    {
        $store = $this->shop();
        StoreDomain::create(['store_id' => $store->id, 'hostname' => 'shop.example.com', 'status' => 'pending_verification', 'verification_token' => 'private-test-token']);
        $item = $this->item($store, 'domain');
        $this->assertTrue($item['ready']);
        $this->assertStringContainsString('masih perlu verifikasi', $item['description']);
        $this->assertStringNotContainsString('private-test-token', json_encode(app(StoreSetup::class)->summary($store)));
        $store->domain->update(['status' => 'active', 'verified_at' => now()]);
        $this->assertStringContainsString('Domain pribadi aktif', $this->item($store->fresh(), 'domain')['description']);
    }

    public function test_draft_hides_post_payment_checklist_but_active_store_keeps_it_available(): void
    {
        $store = $this->shop();
        $store->update(['status' => 'draft']);
        $this->actingAs($store->owner)->get('/stores/'.$store->id.'/subscription')->assertOk()->assertInertia(fn (Assert $page) => $page->where('setup', null));
        $store->update(['status' => 'active', 'expires_at' => now()->addDays(30)]);
        $this->get('/stores/'.$store->id.'/subscription')->assertOk()->assertInertia(fn (Assert $page) => $page->has('setup.items', 5));
        Http::assertNothingSent();
    }
}
