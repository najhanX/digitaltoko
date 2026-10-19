<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\User;
use App\Services\CategoryDestination;
use App\Services\CustomerCommerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CategoryDestinationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'name' => 'Tujuan Demo', 'slug' => 'target-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy', 'template' => 'nebula', 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function product(Store $store): CatalogProduct
    {
        $product = CatalogProduct::factory()->create(['store_id' => $store->id, 'service' => 'Aplikasi premium', 'category' => 'Canva', 'kind' => 'premium', 'sale_price' => 15000, 'active' => true]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'ready', 'cost' => 10000]);

        return $product;
    }

    private function configure(Store $store, CatalogProduct $product, string $type, string $columns = 'auto', string $format = 'concat'): void
    {
        $store->update(['branding' => ['category_destinations' => [$product->category_id => ['type' => $type, 'columns' => $columns]], 'category_games' => [$product->category_id => ['game' => 'none', 'destination_format' => $format]]]]);
    }

    private function order(Store $store, CatalogProduct $product, array $extra = []): CustomerOrder
    {
        $customer = Customer::firstOrCreate(['store_id' => $store->id, 'phone' => '6281234567890'], ['name' => 'Pelanggan', 'phone_verified_at' => now(), 'balance' => 100000]);

        return app(CustomerCommerce::class)->begin($store, [...['product_id' => $product->id, 'phone' => $customer->phone, 'payment' => 'wallet', 'idempotency_key' => (string) Str::uuid()], ...$extra], $customer);
    }

    public function test_premium_uses_verified_customer_phone_and_guest_phone_without_destination(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $order = $this->order($store, $product, ['destination' => 'spoofed-target']);
        $this->assertSame('6281234567890', $order->destination);
        $this->assertSame(85000, Customer::first()->balance);
        app(CustomerCommerce::class)->settings($store)->update(['allow_guest' => true]);
        $this->withSession([])->post('http://'.$store->slug.'.localhost/checkout', ['product_id' => $product->id, 'phone' => '081234567891', 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()])->assertSessionDoesntHaveErrors('destination');
    }

    public function test_phone_and_pln_validate_before_wallet_debit_and_preserve_leading_zeroes(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        foreach (['phone', 'pln'] as $type) {
            $this->configure($store, $product, $type);
            try {
                $this->order($store, $product, ['destination' => 'invalid']);
                $this->fail('Invalid target must be rejected.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('destination', $error->errors());
            }
            $this->assertSame(100000, Customer::first()->balance);
        }
        $this->configure($store, $product, 'phone');
        $this->assertSame('081234567899', $this->order($store, $product, ['destination' => '0812-3456 7899'])->destination);
        $this->configure($store, $product, 'pln');
        $this->assertSame('001234567890', $this->order($store, $product, ['destination' => '001234567890'])->destination);
    }

    public function test_manual_game_formats_are_built_on_server_and_require_both_fields(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $this->configure($store, $product, 'game', '2', 'pipe');
        try {
            $this->order($store, $product, ['destination' => 'bypass', 'destination_id' => '00123456789']);
            $this->fail('Missing server must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('destination_server', $error->errors());
        }
        $order = $this->order($store, $product, ['destination' => 'bypass', 'destination_id' => '00123456789', 'destination_server' => '0123']);
        $this->assertSame('00123456789|0123', $order->destination);
        $this->configure($store, $product, 'game', '1');
        $this->assertSame('Player#TAG', $this->order($store, $product, ['destination_id' => 'Player#TAG'])->destination);
    }

    public function test_category_configuration_applies_to_all_products_and_rejects_wrong_game_field_count(): void
    {
        $store = $this->shop();
        $first = $this->product($store);
        $second = $this->product($store);
        $url = 'http://localhost/stores/'.$store->id.'/categories/'.$first->category_id;
        $this->actingAs($store->owner)->patch($url, ['name' => 'Canva', 'destination_type' => 'phone', 'destination_columns' => 'auto', 'game_validation' => 'none', 'destination_format' => 'auto'])->assertSessionHasNoErrors();
        foreach ([$first, $second] as $product) {
            $this->get('http://'.$store->slug.'.localhost/checkout?product='.$product->id)->assertInertia(fn (Assert $page) => $page->where('destinationProfile.type', 'phone')->where('destinationProfile.columns', 1)->where('gameProfile', null));
        }
        $this->patch($url, ['name' => 'Mobile Legends', 'destination_type' => 'game', 'destination_columns' => '1', 'game_validation' => 'ml'])->assertSessionHasErrors('destination_columns');
        $this->assertSame('phone', $store->fresh()->branding['category_destinations'][$first->category_id]['type']);
        $this->assertSame('Canva', $first->taxonomyCategory->fresh()->name);
        $other = $this->shop();
        $this->actingAs($other->owner)->patch($url, ['name' => 'Canva', 'destination_type' => 'premium'])->assertForbidden();
    }

    public function test_automatic_types_follow_category_service_and_manual_override_wins(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $resolver = app(CategoryDestination::class);
        foreach ([['Pulsa', 'Telkomsel', 'phone'], ['Paket data', 'XL', 'phone'], ['Token listrik', 'PLN', 'pln'], ['Aplikasi premium', 'Canva', 'premium'], ['Game', 'Mobile Legends', 'game'], ['Layanan lain', 'Lainnya', 'other']] as [$service, $category, $type]) {
            $product->update(['service' => $service, 'category' => $category]);
            $product->unsetRelation('taxonomyCategory');
            $this->assertSame($type, $resolver->profile($store, $product->taxonomyCategory)['type']);
        }
        $this->configure($store, $product, 'pln');
        $this->assertSame('pln', $resolver->profile($store, $product->taxonomyCategory, 'premium')['type']);
    }

    public function test_automatic_preview_uses_checkout_resolver_without_saving_and_is_store_scoped(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $category = $product->taxonomyCategory;
        $url = '/stores/'.$store->id.'/catalog-structure/destination-profile?';
        $this->actingAs($store->owner);
        $query = ['service_id' => $category->service_id, 'name' => 'Paket Data XL', 'game_validation' => 'auto'];
        $this->getJson($url.http_build_query($query))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('profile.type', 'premium')->assertJsonPath('profile.columns', 1);
        $this->assertSame('Canva', $category->fresh()->name);
        $this->assertNull($store->fresh()->branding);
        $this->assertDatabaseCount('catalog_categories', 1);
        $query['game_validation'] = 'ml';
        $this->getJson($url.http_build_query($query))->assertOk()->assertJsonPath('profile.type', 'game')->assertJsonPath('profile.columns', 2);
        $other = $this->shop();
        $foreign = $this->product($other);
        $this->getJson($url.http_build_query([...$query, 'service_id' => $foreign->taxonomyCategory->service_id]))->assertNotFound();
        $this->getJson($url.http_build_query([...$query, 'category_id' => $foreign->category_id]))->assertNotFound();
        $this->actingAs($other->owner)->getJson($url.http_build_query($query))->assertForbidden();
    }
}
