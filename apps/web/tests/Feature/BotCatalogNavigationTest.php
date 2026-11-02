<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class BotCatalogNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function shop(string $token): Store
    {
        return Store::create(['owner_id' => User::factory()->create()->id, 'name' => 'Toko Katalog', 'slug' => Str::lower(Str::random(12)), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addDays(30), 'worker_token_hash' => hash('sha256', $token)]);
    }

    private function product(Store $store, string $service, string $category, bool $active = true): CatalogProduct
    {
        return CatalogProduct::create(['store_id' => $store->id, 'code' => Str::random(10), 'name' => $service.' '.$category, 'kind' => 'premium', 'service' => $service, 'category' => $category, 'active' => $active, 'sale_price' => 15000]);
    }

    public function test_services_and_categories_are_grouped_paginated_and_isolated_to_the_authenticated_store(): void
    {
        $token = str_repeat('a', 40);
        $shop = $this->shop($token);
        for ($i = 0; $i < 13; $i++) {
            $this->product($shop, 'Layanan '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Kategori A');
            $this->product($shop, 'Layanan '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Kategori B');
        }
        $this->product($shop, 'Layanan tersembunyi', 'Rahasia', false);
        $this->product($this->shop(str_repeat('b', 40)), 'Toko lain', 'Tidak boleh bocor');
        $this->withToken($token)->getJson('/api/internal/bot/catalog?view=services&page=2')->assertOk()
            ->assertJsonCount(3, 'data.items')->assertJsonPath('data.pagination.total', 13)->assertJsonPath('data.items.0.category_count', 2)
            ->assertJsonPath('data.items.0.product_count', 2)->assertDontSee('Tidak boleh bocor')->assertDontSee('Layanan tersembunyi');
        $this->getJson('/api/internal/bot/catalog?view=categories&service=Layanan%2000')->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Kategori A')->assertJsonPath('data.items.0.service', 'Layanan 00');
        Http::assertNothingSent();
    }

    public function test_duplicate_category_names_require_a_service_and_product_results_never_mix_services(): void
    {
        $token = str_repeat('a', 40);
        $shop = $this->shop($token);
        $target = $this->product($shop, 'Aplikasi Premium', 'Canva Pro');
        $other = $this->product($shop, 'Langganan', 'Canva Pro');
        $this->withToken($token)->getJson('/api/internal/bot/catalog?view=products&category=Canva%20Pro')->assertOk()
            ->assertJsonPath('data.view', 'categories')->assertJsonCount(2, 'data.items');
        $url = '/api/internal/bot/catalog?'.http_build_query(['view' => 'products', 'service' => 'aplikasi premium', 'category' => 'canva pro']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.view', 'products')->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', $target->code)->assertJsonPath('data.items.0.price', 15000)->assertJsonMissingPath('data.items.0.cost')
            ->assertJsonMissingPath('data.items.0.payload')->assertDontSee($other->code);
    }

    public function test_product_pages_group_codes_then_sort_prices_and_numeric_codes_before_paginating(): void
    {
        $token = str_repeat('p', 40);
        $shop = $this->shop($token);
        $products = [];
        foreach (['G-A10' => 5000, 'G-A2' => 5000, 'G-A1' => 8000, 'G-B1' => 1000, 'G-B2' => 2000, 'G-A20' => 12000, 'G-A30' => 16000] as $code => $price) {
            $product = $this->product($shop, 'Layanan Uji', 'Kategori Uji');
            $product->update(['code' => $code, 'sale_price' => $price]);
            $products[$code] = $product;
        }
        $shop->update(['branding' => ['design' => ['sections' => [['key' => 'flash_sale', 'enabled' => true]],
            'flash_product_ids' => [$products['G-A20']->id, $products['G-A30']->id],
            'flash_discounts' => [$products['G-A20']->id => ['type' => 'fixed', 'value' => 10000], $products['G-A30']->id => ['type' => 'percent', 'value' => 75]],
        ]]]);
        $this->product($this->shop(str_repeat('x', 40)), 'Toko lain', 'Kategori Uji')->update(['code' => 'G-A0', 'sale_price' => 1]);
        $expected = ['G-A20', 'G-A30', 'G-A2', 'G-A10', 'G-A1', 'G-B1', 'G-B2'];
        $this->withToken($token);
        foreach (['/api/internal/bot/products?', '/api/internal/bot/catalog?view=products&service=Layanan%20Uji&category=Kategori%20Uji&'] as $url) {
            $first = $this->getJson($url.'per_page=5')->assertOk()->assertJsonPath('data.pagination.total', 7);
            $second = $this->getJson($url.'per_page=5&page=2')->assertOk();
            $this->assertSame($expected, [...array_column($first->json('data.items'), 'code'), ...array_column($second->json('data.items'), 'code')]);
            $this->assertSame([2000, 4000, 5000, 5000, 8000], array_column($first->json('data.items'), 'price'));
        }
        $this->assertSame(array_slice($expected, 0, 5), array_column($this->getJson('/api/internal/bot/products?q=G-A')->assertOk()->json('data.items'), 'code'));
        $shop->update(['branding' => []]);
        $this->assertSame(['G-A2', 'G-A10', 'G-A1', 'G-A20', 'G-A30', 'G-B1', 'G-B2'], array_column($this->getJson('/api/internal/bot/products')->assertOk()->json('data.items'), 'code'));
    }

    public function test_every_level_rejects_missing_auth_suspension_and_expiry(): void
    {
        $token = str_repeat('a', 40);
        $shop = $this->shop($token);
        $this->getJson('/api/internal/bot/catalog?view=services')->assertUnauthorized();
        foreach ([['admin_suspended_at' => now()], ['expires_at' => now()->subDays(8)]] as $changes) {
            $shop->update(['admin_suspended_at' => null, 'expires_at' => now()->addDay(), 'category' => 'full', ...$changes]);
            foreach (['services', 'categories', 'products'] as $view) {
                $this->withToken($token)->getJson('/api/internal/bot/catalog?'.http_build_query(['view' => $view, 'service' => 'Pulsa', 'category' => 'Telkomsel']))->assertForbidden();
            }
        }
    }

    public function test_scopes_are_validated_and_category_paging_keeps_service_context(): void
    {
        $token = str_repeat('a', 40);
        $shop = $this->shop($token);
        for ($i = 0; $i < 12; $i++) {
            $this->product($shop, 'Pulsa', 'Operator '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }
        $this->withToken($token)->getJson('/api/internal/bot/catalog?view=categories&service=Pulsa&page=2')->assertOk()
            ->assertJsonPath('data.service', 'Pulsa')->assertJsonPath('data.pagination.total', 12)->assertJsonCount(2, 'data.items');
        $this->getJson('/api/internal/bot/catalog?view=categories')->assertUnprocessable();
        $this->getJson('/api/internal/bot/catalog?view=products')->assertUnprocessable();
        $this->getJson('/api/internal/bot/catalog?view=other')->assertUnprocessable();
        $this->getJson('/api/internal/bot/catalog?view=categories&service='.str_repeat('a', 101))->assertUnprocessable();
        $this->getJson('/api/internal/bot/catalog?view=categories&service='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonCount(0, 'data.items');
        $this->getJson('/api/internal/bot/catalog?view=products&category=Operator%2000')->assertOk()->assertJsonPath('data.service', 'Pulsa');
        for ($i = 0; $i < 12; $i++) {
            $this->product($shop, 'Pulsa', 'Kategori banyak produk');
        }
        $this->product($shop, 'Data', 'Kategori banyak produk');
        $this->getJson('/api/internal/bot/catalog?'.http_build_query(['view' => 'products', 'service' => 'Pulsa', 'category' => 'Kategori banyak produk', 'page' => 2]))->assertOk()
            ->assertJsonPath('data.pagination.total', 12)->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.service', 'Pulsa');
    }
}
