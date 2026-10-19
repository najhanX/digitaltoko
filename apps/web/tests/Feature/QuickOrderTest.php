<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\QuickOrderCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'name' => 'Toko Quick', 'slug' => 'quick-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy', 'template' => 'aurora', 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function product(Store $store, string $service = 'Pulsa', string $category = 'Telkomsel', array $extra = []): CatalogProduct
    {
        $connection = SupplierConnection::firstOrCreate(['store_id' => $store->id, 'kind' => 'hanshop'], ['enabled' => true, 'synced_at' => now()]);
        $item = SupplierProduct::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'sku' => Str::random(12), 'name' => 'Sumber', 'kind' => 'ppob', 'service' => $service, 'category' => $category, 'cost' => 10000, 'available' => true, 'observed_at' => now()]);
        $product = CatalogProduct::factory()->create([...['store_id' => $store->id, 'name' => $category.' '.Str::random(6), 'kind' => 'ppob', 'service' => $service, 'category' => $category, 'sale_price' => 15000, 'active' => true], ...$extra]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'hanshop', 'supplier_product_id' => $item->id, 'source_key' => 'item:'.$item->id, 'active' => true]);

        $provider = ['Telkomsel' => 'telkomsel', 'XL' => 'xl', 'AXIS' => 'axis', 'by.U' => 'byu', 'Tri' => 'tri'][$category] ?? null;
        if ($provider) {
            $branding = $store->fresh()->branding ?? [];
            $branding['category_providers'][$product->category_id] = $provider;
            $store->update(['branding' => $branding]);
        }

        return $product;
    }

    private function url(Store $store, string $path): string
    {
        return 'http://'.$store->slug.'.localhost'.$path;
    }

    public function test_catalog_is_provider_service_scoped_paginated_and_uses_real_markup_prices(): void
    {
        $store = $this->shop();
        for ($index = 0; $index < 8; $index++) {
            $this->product($store);
        }
        $this->product($store, 'Pulsa', 'XL');
        $this->product($store, 'Paket Data', 'Telkomsel');
        $this->product($store, 'Voucher Data', 'Telkomsel');
        $this->product($this->shop());
        $this->product($store, extra: ['active' => false]);
        $this->product($store, extra: ['kind' => 'postpaid']);
        PricingRule::create(['store_id' => $store->id, 'scope' => 'service', 'key' => 'pulsa', 'markup' => '10%', 'auto_update' => true]);
        $url = $this->url($store, '/quick-order/products?provider=telkomsel&service=pulsa');
        $this->getJson($url)->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('pagination.total', 8)->assertJsonPath('items.0.price', 11000)
            ->assertJsonPath('items.0.available', true)->assertJsonMissingPath('items.0.cost')->assertJsonMissingPath('items.0.candidates');
        $this->getJson($url.'&page=2')->assertJsonCount(3, 'items');
        $this->getJson($this->url($store, '/quick-order/products?provider=telkomsel&service=data'))->assertJsonCount(1, 'items');
        $this->getJson($this->url($store, '/quick-order/products?provider=telkomsel&service=voucher'))->assertJsonCount(1, 'items');
        $this->getJson($this->url($store, '/quick-order/products?provider=xl&service=pulsa'))->assertJsonCount(1, 'items');
        $this->getJson($this->url($store, '/quick-order/products?provider=unknown&service=pulsa'))->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_continuation_prefills_destination_privately_without_creating_an_order(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $this->get($this->url($store, '/'))->assertInertia(fn (Assert $page) => $page->has('quickOrderProviders', 7));
        $response = $this->post($this->url($store, '/quick-order/checkout'), ['product_id' => $product->id, 'destination' => '081234567890']);
        $response->assertRedirect('/checkout?product='.$product->id)->assertSessionHas('quick_order.destination', '081234567890');
        $this->assertStringNotContainsString('081234567890', $response->headers->get('Location'));
        $this->get($this->url($store, '/checkout?product='.$product->id))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('initialDestination', '081234567890')->where('destinationProfile.type', 'phone')->where('customer', null));
        $this->get($this->url($store, '/checkout?product='.$product->id))->assertInertia(fn (Assert $page) => $page->where('initialDestination', ''));
        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_continuation_rejects_foreign_inactive_and_wrong_products_and_invalid_numbers(): void
    {
        $store = $this->shop();
        $foreign = $this->product($this->shop());
        $game = $this->product($store, 'Game', 'Mobile Legends');
        $inactive = $this->product($store, extra: ['active' => false]);
        $url = $this->url($store, '/quick-order/checkout');
        $this->postJson($url, ['product_id' => $foreign->id, 'destination' => '081234567890'])->assertNotFound();
        $this->postJson($url, ['product_id' => $inactive->id, 'destination' => '081234567890'])->assertNotFound();
        $this->postJson($url, ['product_id' => $game->id, 'destination' => '081234567890'])->assertUnprocessable();
        $this->postJson($url, ['product_id' => $game->id, 'destination' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('destination');
        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_explicit_provider_works_without_provider_name_and_ignores_misleading_category_names(): void
    {
        $store = $this->shop();
        $quick = app(QuickOrderCatalog::class);
        foreach ([['Data', 'XL', 'xl', 'data'], ['Voucher Data', 'AXIS', 'axis', 'voucher'], ['Pulsa', 'by.U', 'byu', 'pulsa'], ['Pulsa', 'Tri', 'tri', 'pulsa']] as [$service, $category, $provider, $type]) {
            $product = $this->product($store, $service, $category);
            $this->assertSame(['provider' => $provider, 'service' => $type], $quick->category($store, $product->taxonomyCategory));
        }
        $product = $this->product($store, 'Paket Data', 'Promo Telkomsel');
        $this->assertNull($quick->category($store, $product->taxonomyCategory));
        $branding = $store->branding ?? [];
        $branding['category_providers'][$product->category_id] = 'xl';
        $store->update(['branding' => $branding]);
        $this->assertSame(['provider' => 'xl', 'service' => 'data'], $quick->category($store, $product->taxonomyCategory));
        $this->getJson($this->url($store, '/quick-order/products?provider=xl&service=data'))->assertJsonCount(2, 'items');
        $this->getJson($this->url($store, '/quick-order/products?provider=telkomsel&service=data'))->assertJsonCount(0, 'items');
        $product->taxonomyCategory->update(['name' => 'Promo Bulanan']);
        $this->assertSame(['provider' => 'xl', 'service' => 'data'], $quick->category($store, $product->taxonomyCategory));
        $this->assertNull($quick->category($store, $this->product($store, 'Token Listrik', 'PLN')->taxonomyCategory));
        $this->assertNull($quick->category($store, $this->product($store, 'Pulsa', 'Netflix')->taxonomyCategory));
    }

    public function test_quick_order_stops_when_web_or_store_is_unavailable(): void
    {
        $store = $this->shop();
        $this->product($store);
        $url = $this->url($store, '/quick-order/products?provider=telkomsel&service=pulsa');
        app(CustomerCommerce::class)->settings($store)->update(['closing' => true]);
        $this->getJson($url)->assertNotFound();
        app(CustomerCommerce::class)->settings($store)->update(['closing' => false]);
        foreach ([['category' => 'bot'], ['category' => 'full', 'status' => 'suspended_manual'], ['status' => 'active', 'template' => 'nebula']] as $change) {
            $store->update($change);
            $this->getJson($url)->assertNotFound();
        }
    }
}
