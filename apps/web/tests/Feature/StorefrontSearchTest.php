<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorefrontSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'cache.default' => 'array', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function shop(string $template = 'nebula'): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner'])->id,
            'name' => 'Search shop', 'slug' => Str::lower(Str::random(12)), 'category' => 'full', 'plan' => 'galaxy',
            'template' => $template, 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function product(Store $store, string $name, bool $active = true): CatalogProduct
    {
        $product = CatalogProduct::factory()->create(['store_id' => $store->id, 'name' => $name, 'category' => 'Canva', 'service' => 'Aplikasi premium', 'kind' => 'premium', 'active' => $active, 'sale_price' => 15000]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock']);
        PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'ready', 'cost' => 10000, 'payload' => 'PRIVATE-STOCK-SECRET']);

        return $product;
    }

    private function url(Store $store): string
    {
        return 'http://'.$store->slug.'.localhost/search';
    }

    public function test_search_returns_local_categories_and_products_without_private_data_or_supplier_requests(): void
    {
        foreach (['nebula', 'aurora', 'atlas'] as $template) {
            $store = $this->shop($template);
            $product = $this->product($store, 'Canva Annual');
            $this->product($store, 'Canva Hidden', false);
            $this->product($this->shop(), 'Canva Foreign');
            $response = $this->getJson($this->url($store).'?q=Canva')->assertOk()
                ->assertJsonCount(1, 'categories')->assertJsonCount(1, 'products')
                ->assertJsonPath('categories.0.id', $product->category_id)
                ->assertJsonPath('categories.0.url', '/kategori/'.$product->category_id)
                ->assertJsonPath('products.0.id', $product->id)
                ->assertJsonPath('products.0.url', '/kategori/'.$product->category_id.'?product='.$product->id)
                ->assertJsonPath('products.0.price', 15000)->assertJsonPath('products.0.available', true)
                ->assertJsonMissingPath('products.0.cost')->assertJsonMissingPath('products.0.mappings');
            $this->assertStringNotContainsString('PRIVATE-STOCK-SECRET', $response->getContent());
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        Http::assertNothingSent();
    }

    public function test_search_is_bounded_and_accepts_product_codes(): void
    {
        $store = $this->shop();
        $first = null;
        for ($index = 0; $index < 20; $index++) {
            $product = $this->product($store, 'Canva '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            $product->update(['category' => 'Canva '.$index]);
            $first ??= $product;
        }
        $this->getJson($this->url($store).'?q=Canva')->assertOk()->assertJsonCount(5, 'categories')->assertJsonCount(8, 'products');
        $this->getJson($this->url($store).'?q='.urlencode($first->code))->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $first->id);
        $this->getJson($this->url($store).'?q='.str_repeat('x', 81))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson($this->url($store).'?q[]=Canva')->assertUnprocessable()->assertJsonValidationErrors('q');
        Http::assertNothingSent();
    }

    public function test_scrolling_pages_keeps_results_relevant_scoped_and_without_repeating_rows(): void
    {
        $store = $this->shop();
        $expected = [];
        for ($index = 0; $index < 21; $index++) {
            $product = $this->product($store, 'Canva '.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
            $product->update(['category' => 'Canva '.str_pad((string) $index, 2, '0', STR_PAD_LEFT)]);
            $expected[] = $product->id;
        }
        $this->product($store, 'Spotify unrelated')->update(['category' => 'Music']);
        $this->product($store, 'Canva inactive', false);
        $this->product($this->shop(), 'Canva another store');
        $products = [];
        $categories = [];
        for ($page = 1; $page <= 5; $page++) {
            $response = $this->getJson($this->url($store).'?q=Canva&page='.$page)->assertOk()
                ->assertJsonPath('pagination.page', $page)->assertJsonPath('pagination.has_more', $page < 5);
            $this->assertLessThanOrEqual(8, count($response->json('products')));
            $this->assertLessThanOrEqual(5, count($response->json('categories')));
            $products = array_merge($products, array_column($response->json('products'), 'id'));
            $categories = array_merge($categories, array_column($response->json('categories'), 'id'));
        }
        $this->assertEqualsCanonicalizing($expected, $products);
        $this->assertCount(21, array_unique($products));
        $this->assertCount(21, array_unique($categories));
        $this->getJson($this->url($store).'?q=Canva&page=6')->assertOk()
            ->assertJsonCount(0, 'products')->assertJsonCount(0, 'categories')->assertJsonPath('pagination.has_more', false);
        $this->getJson($this->url($store).'?q=Spotify&page=1')->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('pagination.has_more', false);
        $this->getJson($this->url($store).'?q=Spotify&page=2')->assertOk()->assertJsonCount(0, 'products')->assertJsonCount(0, 'categories');
        foreach (['0', '-1', '10001', 'invalid'] as $page) {
            $this->getJson($this->url($store).'?q=Canva&page='.$page)->assertUnprocessable()->assertJsonValidationErrors('page');
        }
        Http::assertNothingSent();
    }

    public function test_blank_short_unmatched_and_unavailable_store_search_do_not_return_catalog(): void
    {
        $store = $this->shop();
        $this->product($store, 'Canva Annual');
        foreach (['', 'C', 'not-in-catalog'] as $term) {
            $this->getJson($this->url($store).'?q='.urlencode($term))->assertOk()->assertExactJson(['categories' => [], 'products' => [], 'pagination' => ['page' => 1, 'has_more' => false]]);
        }
        $store->update(['expires_at' => now()->subDays(8)]);
        $this->getJson($this->url($store).'?q=Canva')->assertOk()->assertExactJson(['categories' => [], 'products' => [], 'pagination' => ['page' => 1, 'has_more' => false]]);
        $this->getJson('http://localhost/search?q=Canva')->assertNotFound();
        Http::assertNothingSent();
    }
}
