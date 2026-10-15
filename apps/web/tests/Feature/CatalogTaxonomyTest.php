<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\PricingRule;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\ProductPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CatalogTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'name' => 'Toko Struktur', 'slug' => Str::lower(Str::random(10)), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
    }

    private function product(Store $store, string $service = 'Pulsa', string $category = 'Telkomsel'): CatalogProduct
    {
        return CatalogProduct::create(['store_id' => $store->id, 'name' => 'Produk tes', 'code' => Str::random(10), 'kind' => 'premium', 'service' => $service, 'category' => $category, 'active' => true, 'sale_price' => 15000]);
    }

    public function test_owner_can_create_structure_and_delete_only_empty_categories_and_services(): void
    {
        $store = $this->shop();
        $base = '/stores/'.$store->id;
        $this->actingAs($store->owner)->post($base.'/services', ['name' => 'Aplikasi Premium'])->assertRedirect()->assertSessionHasNoErrors();
        $service = CatalogService::firstOrFail();
        $this->post($base.'/services', ['name' => 'aplikasi premium'])->assertSessionHasErrors('name');
        $this->post($base.'/categories', ['service_id' => $service->id, 'name' => 'Canva'])->assertSessionHasNoErrors();
        $category = CatalogCategory::firstOrFail();
        $this->delete($base.'/services/'.$service->id)->assertSessionHasErrors('delete');
        $this->post($base.'/categories', ['service_id' => $service->id, 'name' => 'CANVA'])->assertSessionHasErrors('name');
        $product = $this->product($store, $service->name, $category->name);
        $this->assertSame($category->id, $product->category_id);
        $this->delete($base.'/categories/'.$category->id)->assertSessionHasErrors('delete');
        $product->delete();
        $this->delete($base.'/categories/'.$category->id)->assertSessionHasNoErrors();
        $this->delete($base.'/services/'.$service->id)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('catalog_services', 0);
        $this->assertDatabaseCount('catalog_categories', 0);
        Http::assertNothingSent();
    }

    public function test_staff_permissions_and_store_boundaries_apply_to_every_mutation_and_picker(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $foreign = $this->product($other)->taxonomyCategory->service;
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'reader', 'email' => 'staff@test.invalid', 'password' => 'test-only-password', 'active' => true, 'permissions' => ['products.view']]);
        $base = '/stores/'.$store->id;
        $this->actingAs($staff, 'staff')->get($base.'/catalog-structure')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('role', 'staff')->where('canManage', false));
        $this->post($base.'/services', ['name' => 'Terlarang'])->assertForbidden();
        $this->get('/stores/'.$other->id.'/catalog-structure')->assertForbidden();
        $staff->update(['permissions' => ['products.manage']]);
        $this->post($base.'/services', ['name' => 'Layanan staff'])->assertSessionHasNoErrors();
        $this->patch($base.'/services/'.$foreign->id, ['name' => 'Lintas toko'])->assertNotFound();
        $this->post($base.'/categories', ['service_id' => $foreign->id, 'name' => 'Lintas toko'])->assertSessionHasErrors('service_id');
        $this->getJson($base.'/catalog-structure/options?type=categories&service_id='.$foreign->id)->assertNotFound();
        $store->update(['admin_suspended_at' => now()]);
        $this->post($base.'/services', ['name' => 'Ditangguhkan'])->assertForbidden();
    }

    public function test_renames_propagate_to_products_and_preserve_pricing_and_bot_navigation(): void
    {
        $store = $this->shop();
        $store->update(['worker_token_hash' => hash('sha256', str_repeat('t', 40))]);
        $product = $this->product($store);
        $category = $product->taxonomyCategory;
        $service = $category->service;
        PricingRule::create(['store_id' => $store->id, 'scope' => 'service', 'key' => 'pulsa', 'markup' => '5000', 'auto_update' => true]);
        PricingRule::create(['store_id' => $store->id, 'scope' => 'category', 'key' => 'telkomsel', 'markup' => '5%', 'auto_update' => true]);
        $base = '/stores/'.$store->id;
        $this->actingAs($store->owner)->patch($base.'/services/'.$service->id, ['name' => 'Pulsa Reguler'])->assertSessionHasNoErrors();
        $this->patch($base.'/categories/'.$category->id, ['name' => 'Telkomsel Nasional'])->assertSessionHasNoErrors();
        $product->refresh();
        $this->assertSame('Pulsa Reguler', $product->service);
        $this->assertSame('Telkomsel Nasional', $product->category);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame(15000, $product->sale_price);
        app(ProductPricing::class)->forget();
        $this->assertSame('5%', app(ProductPricing::class)->resolve($product)['markup']);
        $this->assertDatabaseHas('pricing_rules', ['scope' => 'service', 'key' => 'pulsa reguler', 'markup' => '5000']);
        $this->withToken(str_repeat('t', 40))->getJson('/api/internal/bot/catalog?view=categories&service=Pulsa%20Reguler')->assertOk()->assertJsonPath('data.items.0.name', 'Telkomsel Nasional');
    }

    public function test_same_category_name_in_other_services_keeps_its_rule_and_conflicting_target_rule_rolls_back(): void
    {
        $store = $this->shop();
        $first = $this->product($store, 'Aplikasi', 'Canva');
        $second = $this->product($store, 'Bundling', 'Canva');
        PricingRule::create(['store_id' => $store->id, 'scope' => 'category', 'key' => 'canva', 'markup' => '7000']);
        $base = '/stores/'.$store->id;
        $this->actingAs($store->owner)->patch($base.'/categories/'.$first->category_id, ['name' => 'Canva Pro'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('pricing_rules', ['scope' => 'category', 'key' => 'canva', 'markup' => '7000']);
        $this->assertDatabaseHas('pricing_rules', ['scope' => 'category', 'key' => 'canva pro', 'markup' => '7000']);
        $this->assertSame('Canva', $second->fresh()->category);
        PricingRule::create(['store_id' => $store->id, 'scope' => 'category', 'key' => 'harga lain', 'markup' => '99%']);
        $this->patch($base.'/categories/'.$first->category_id, ['name' => 'Harga lain'])->assertSessionHasErrors('name');
        $this->assertSame('Canva Pro', $first->fresh()->category);
    }

    public function test_product_form_accepts_only_a_category_from_its_store_and_uses_canonical_names(): void
    {
        $store = $this->shop();
        $category = $this->product($store, 'Aplikasi Premium', 'Canva')->taxonomyCategory;
        $foreign = $this->product($this->shop())->taxonomyCategory;
        $input = ['code' => 'NEW1', 'name' => 'Produk pilihan', 'kind' => 'premium', 'category_id' => $category->id,
            'service' => 'Nama palsu', 'category' => 'Kategori palsu', 'active' => true, 'mapping_ids' => [], 'use_stock' => true, 'sale_price' => 10000];
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/products', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('catalog_products', ['store_id' => $store->id, 'code' => 'NEW1', 'category_id' => $category->id, 'service' => 'Aplikasi Premium', 'category' => 'Canva']);
        $this->post('/stores/'.$store->id.'/products', [...$input, 'code' => 'NEW2', 'category_id' => $foreign->id])->assertSessionHasErrors('category_id');
        $this->get('/stores/'.$store->id.'/products?category_id='.$category->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pagination.total', 2)->where('selectedCategory.name', 'Canva')->where('products.0.service_id', $category->service_id));
    }

    public function test_lists_and_pickers_are_paginated_searchable_and_scoped_to_service(): void
    {
        $store = $this->shop();
        for ($i = 0; $i < 12; $i++) {
            $this->product($store, 'Layanan '.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Kategori sama');
        }
        $base = '/stores/'.$store->id;
        $service = CatalogService::where('store_id', $store->id)->orderBy('name')->firstOrFail();
        $this->actingAs($store->owner)->get($base.'/catalog-structure?per_page=5&page=2')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('items', 5)->where('pagination.total', 12)->where('items.0.categories_count', 1)->where('items.0.products_count', 1));
        $this->get($base.'/catalog-structure?tab=categories&service_id='.$service->id)->assertInertia(fn (AssertableInertia $page) => $page->has('items', 1)->where('selectedService.id', $service->id));
        $this->getJson($base.'/catalog-structure/options?type=services&page=2')->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('pagination.total', 12);
        $this->getJson($base.'/catalog-structure/options?type=categories&service_id='.$service->id)->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($base.'/catalog-structure/options?type=services&q=11')->assertJsonCount(1, 'items');
    }

    public function test_pricing_suggestions_search_paginate_and_deduplicate_categories_with_staff_access(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $this->product($store, 'Aplikasi', 'Canva');
        $this->product($store, 'Bundling', 'CANVA');
        for ($i = 0; $i < 7; $i++) {
            $this->product($store, 'Aplikasi', 'Kategori '.$i);
        }
        $this->product($other, 'Rahasia toko lain', 'Kategori rahasia');
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Harga', 'username' => 'pricing', 'email' => 'pricing@test.invalid', 'password' => 'test-only-password', 'active' => true, 'permissions' => ['pricing.manage']]);
        $base = '/stores/'.$store->id.'/catalog-structure/options';
        $this->actingAs($staff, 'staff')->getJson($base.'?type=pricing_categories')->assertOk()
            ->assertJsonCount(5, 'items')->assertJsonPath('pagination.total', 8)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($base.'?type=pricing_categories&page=2')->assertOk()->assertJsonCount(3, 'items');
        $this->getJson($base.'?type=pricing_categories&q=canva')->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($base.'?type=pricing_categories&q=rahasia')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($base.'?type=services&q=bund')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Bundling');
        $this->getJson('/stores/'.$other->id.'/catalog-structure/options?type=pricing_categories')->assertForbidden();
        $staff->update(['permissions' => ['dashboard.view']]);
        $this->getJson($base.'?type=pricing_categories')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_migration_backfills_legacy_products_without_changing_existing_values_or_ids(): void
    {
        $store = $this->shop();
        $migration = require database_path('migrations/2026_10_15_035333_create_catalog_taxonomy_tables.php');
        $migration->down();
        foreach (['Pulsa', 'pulsa'] as $index => $service) {
            DB::table('catalog_products')->insert(['id' => (string) Str::uuid7(), 'store_id' => $store->id, 'code' => 'OLD'.$index, 'name' => 'Produk lama '.$index, 'kind' => 'premium', 'service' => $service, 'category' => 'Telkomsel', 'active' => true, 'sale_price' => 10000 + $index, 'created_at' => now(), 'updated_at' => now()]);
        }
        $before = DB::table('catalog_products')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        $after = DB::table('catalog_products')->orderBy('id')->get()->map(fn ($row) => collect((array) $row)->except('category_id')->all())->all();
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('catalog_services', 1);
        $this->assertDatabaseCount('catalog_categories', 1);
        $this->assertSame(0, DB::table('catalog_products')->whereNull('category_id')->count());
    }
}
