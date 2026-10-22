<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\PremiumStock;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\CategoryDestination;
use App\Services\ProductPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_bulk_categories_move_products_and_update_only_selected_settings(): void
    {
        $store = $this->shop();
        $first = $this->product($store, 'Produk digital', 'Paket A');
        $second = $this->product($store, 'Produk digital', 'Paket B');
        $untouched = $this->product($store, 'Produk digital', 'Paket C');
        $destination = CatalogService::create(['store_id' => $store->id, 'name' => 'Paket Data', 'name_key' => 'paket data']);
        $store->update(['branding' => ['category_images' => [$first->category_id => 'https://example.test/image.png'], 'category_games' => [$first->category_id => ['game' => 'none', 'destination_format' => 'pipe']]]]);
        $this->actingAs($store->owner)->postJson('/stores/'.$store->id.'/categories/bulk', ['ids' => [$first->category_id, $second->category_id], 'changes' => ['service_id' => $destination->id, 'provider' => 'telkomsel', 'destination_type' => 'phone']])->assertRedirect();
        $this->assertSame('Paket Data', $first->fresh()->service);
        $this->assertSame('Paket Data', $second->fresh()->service);
        $this->assertSame('Produk digital', $untouched->fresh()->service);
        $this->assertSame($destination->id, CatalogCategory::find($first->category_id)->service_id);
        $branding = $store->fresh()->branding;
        $this->assertSame('telkomsel', $branding['category_providers'][$first->category_id]);
        $this->assertSame('phone', $branding['category_destinations'][$second->category_id]['type']);
        $this->assertSame('pipe', $branding['category_games'][$first->category_id]['destination_format']);
        $this->assertSame('https://example.test/image.png', $branding['category_images'][$first->category_id]);
        $this->assertSame(15000, $first->fresh()->sale_price);
        $this->postJson('/stores/'.$store->id.'/categories/bulk', ['ids' => [$first->category_id], 'changes' => ['provider' => null]])->assertRedirect();
        $this->assertArrayNotHasKey($first->category_id, $store->fresh()->branding['category_providers']);
        $this->assertSame('telkomsel', $store->fresh()->branding['category_providers'][$second->category_id]);
        Http::assertNothingSent();
    }

    public function test_bulk_category_name_collision_rolls_back_every_change(): void
    {
        $store = $this->shop();
        $first = $this->product($store, 'Produk digital', 'Sama');
        $second = $this->product($store, 'Lainnya', 'Sama');
        $target = CatalogService::create(['store_id' => $store->id, 'name' => 'Pulsa', 'name_key' => 'pulsa']);
        $url = '/stores/'.$store->id.'/categories/bulk';
        $this->actingAs($store->owner)->postJson($url, ['ids' => [$first->category_id, $second->category_id], 'changes' => ['service_id' => $target->id, 'provider' => 'telkomsel']])->assertUnprocessable()->assertJsonValidationErrors('changes.service_id');
        $this->assertSame('Produk digital', $first->fresh()->service);
        $this->assertSame('Lainnya', $second->fresh()->service);
        $this->product($store, 'Pulsa', 'Sama');
        $this->postJson($url, ['ids' => [$first->category_id], 'changes' => ['service_id' => $target->id]])->assertUnprocessable();
        $this->assertSame('Produk digital', $first->fresh()->service);
    }

    public function test_bulk_category_permissions_and_store_boundaries_are_enforced(): void
    {
        $store = $this->shop();
        $first = $this->product($store);
        $other = $this->shop();
        $foreign = $this->product($other);
        $url = '/stores/'.$store->id.'/categories/bulk';
        $this->actingAs($store->owner)->postJson($url, ['ids' => [$first->category_id, $foreign->category_id], 'changes' => ['destination_type' => 'phone']])->assertUnprocessable();
        $this->assertEmpty($store->fresh()->branding);
        $this->postJson($url, ['ids' => [$first->category_id], 'changes' => []])->assertUnprocessable();
        $this->postJson($url, ['ids' => [$first->category_id], 'changes' => ['service_id' => $foreign->taxonomyCategory->service_id]])->assertUnprocessable();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Viewer', 'username' => 'bulk-viewer', 'email' => 'bulk-viewer@test.invalid', 'password' => 'test-only-password', 'active' => true, 'permissions' => ['products.view']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->postJson($url, ['ids' => [$first->category_id], 'changes' => ['destination_type' => 'phone']])->assertForbidden();
        $staff->update(['permissions' => ['products.manage']]);
        $this->postJson($url, ['ids' => [$first->category_id], 'changes' => ['destination_type' => 'phone']])->assertRedirect();
        $this->assertSame('phone', $store->fresh()->branding['category_destinations'][$first->category_id]['type']);
        $this->postJson('/stores/'.$other->id.'/categories/bulk', ['ids' => [$foreign->category_id], 'changes' => ['destination_type' => 'phone']])->assertForbidden();
    }

    public function test_bulk_provider_and_game_column_conflicts_do_not_leave_partial_updates(): void
    {
        $store = $this->shop();
        $first = $this->product($store, 'Produk digital', 'Umum');
        $game = $this->product($store, 'Game', 'Mobile Legends');
        $url = '/stores/'.$store->id.'/categories/bulk';
        $this->actingAs($store->owner)->postJson($url, ['ids' => [$first->category_id, $game->category_id], 'changes' => ['provider' => 'telkomsel']])->assertUnprocessable()->assertJsonValidationErrors('changes.provider');
        $this->postJson($url, ['ids' => [$first->category_id, $game->category_id], 'changes' => ['destination_columns' => '1']])->assertUnprocessable()->assertJsonValidationErrors('changes.destination_columns');
        $this->assertEmpty($store->fresh()->branding);
        $this->postJson($url, ['ids' => [$game->category_id], 'changes' => ['destination_type' => 'game', 'game_validation' => 'none', 'destination_columns' => '1', 'destination_format' => 'id']])->assertRedirect();
        $this->assertSame(1, app(CategoryDestination::class)->profile($store->fresh(), $game->taxonomyCategory)['columns']);
    }

    public function test_bulk_delete_empty_categories_cleans_settings_and_keeps_shared_images(): void
    {
        Storage::fake('local');
        $store = $this->shop();
        $service = CatalogService::create(['store_id' => $store->id, 'name' => 'Pulsa', 'name_key' => 'pulsa']);
        $categories = collect(['A', 'B', 'C'])->map(fn ($name) => CatalogCategory::create(['store_id' => $store->id, 'service_id' => $service->id, 'name' => $name, 'name_key' => strtolower($name)]));
        $shared = 'storefront/'.$store->id.'/shared-category-image-123.png';
        $exclusive = 'storefront/'.$store->id.'/exclusive-category-image-123.png';
        Storage::disk('local')->put($shared, 'test image');
        Storage::disk('local')->put($exclusive, 'test image');
        $sharedUrl = '/storefront-media/'.$store->id.'/shared-category-image-123.png';
        $exclusiveUrl = '/storefront-media/'.$store->id.'/exclusive-category-image-123.png';
        $branding = ['category_images' => [$categories[0]->id => $sharedUrl, $categories[1]->id => $exclusiveUrl, $categories[2]->id => $sharedUrl]];
        foreach (['category_image_preferences', 'category_games', 'category_destinations', 'category_providers'] as $key) {
            $branding[$key] = [$categories[0]->id => 'fixture'];
        }
        $store->update(['branding' => $branding]);
        $this->actingAs($store->owner)->postJson('/stores/'.$store->id.'/catalog-structure/bulk-delete', ['type' => 'categories', 'ids' => [$categories[0]->id, $categories[1]->id]])->assertRedirect();
        $this->assertNull($categories[0]->fresh());
        $this->assertNull($categories[1]->fresh());
        $this->assertNotNull($categories[2]->fresh());
        foreach (array_keys($branding) as $key) {
            $this->assertArrayNotHasKey($categories[0]->id, $store->fresh()->branding[$key]);
        }
        Storage::disk('local')->assertExists($shared);
        Storage::disk('local')->assertMissing($exclusive);
    }

    public function test_bulk_delete_refuses_all_items_when_one_is_still_used(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $emptyService = CatalogService::create(['store_id' => $store->id, 'name' => 'Kosong', 'name_key' => 'kosong']);
        $emptyCategory = CatalogCategory::create(['store_id' => $store->id, 'service_id' => $emptyService->id, 'name' => 'Kosong', 'name_key' => 'kosong']);
        $url = '/stores/'.$store->id.'/catalog-structure/bulk-delete';
        $this->actingAs($store->owner)->postJson($url, ['type' => 'categories', 'ids' => [$emptyCategory->id, $product->category_id]])->assertUnprocessable()->assertJsonValidationErrors('delete');
        $this->assertNotNull($emptyCategory->fresh());
        $this->assertNotNull($product->fresh());
        $this->postJson($url, ['type' => 'services', 'ids' => [$emptyService->id, $product->taxonomyCategory->service_id]])->assertUnprocessable()->assertJsonValidationErrors('delete');
        $this->assertNotNull($emptyService->fresh());
    }

    public function test_bulk_delete_services_requires_manage_permission_and_own_store_ids(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $services = collect(['A', 'B'])->map(fn ($name) => CatalogService::create(['store_id' => $store->id, 'name' => $name, 'name_key' => strtolower($name)]));
        $foreign = CatalogService::create(['store_id' => $other->id, 'name' => 'Foreign', 'name_key' => 'foreign']);
        $url = '/stores/'.$store->id.'/catalog-structure/bulk-delete';
        $this->actingAs($store->owner)->postJson($url, ['type' => 'services', 'ids' => [$services[0]->id, $foreign->id]])->assertUnprocessable();
        $this->assertNotNull($services[0]->fresh());
        $this->postJson($url, ['type' => 'services', 'ids' => [$services[0]->id, $services[0]->id]])->assertUnprocessable();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Viewer', 'username' => 'delete-viewer', 'email' => 'delete-viewer@test.invalid', 'password' => 'test-password', 'active' => true, 'permissions' => ['products.view']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->postJson($url, ['type' => 'services', 'ids' => $services->pluck('id')->all()])->assertForbidden();
        $staff->update(['permissions' => ['products.manage']]);
        $this->postJson($url, ['type' => 'services', 'ids' => $services->pluck('id')->all()])->assertRedirect();
        foreach ($services as $service) {
            $this->assertNull($service->fresh());
        }
        $this->assertNotNull($foreign->fresh());
        Http::assertNothingSent();
    }

    public function test_category_provider_is_validated_scoped_and_removed_when_moved_or_deleted(): void
    {
        $store = $this->shop();
        $base = '/stores/'.$store->id;
        $this->actingAs($store->owner)->post($base.'/services', ['name' => 'Paket Data'])->assertSessionHasNoErrors();
        $service = CatalogService::where('store_id', $store->id)->firstOrFail();
        $this->post($base.'/categories', ['service_id' => $service->id, 'name' => 'Promo Bulanan', 'provider' => 'xl'])->assertSessionHasNoErrors();
        $category = CatalogCategory::where('service_id', $service->id)->firstOrFail();
        $this->assertSame('xl', $store->fresh()->branding['category_providers'][$category->id]);
        $this->get($base.'/catalog-structure?tab=categories')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('items.0.provider', 'xl')->where('items.0.quick_order_service', 'data')->has('providerOptions', 7));
        $this->getJson($base.'/catalog-structure/options?type=services')->assertJsonPath('items.0.quick_order_service', 'data');
        $this->patch($base.'/categories/'.$category->id, ['name' => 'Promo Bulanan', 'provider' => 'unknown'])->assertSessionHasErrors('provider');
        $this->assertSame('xl', $store->fresh()->branding['category_providers'][$category->id]);
        $foreign = $this->shop();
        $this->actingAs($foreign->owner)->patch($base.'/categories/'.$category->id, ['name' => 'Asing', 'provider' => 'tri'])->assertForbidden();
        $this->actingAs($store->owner)->post($base.'/services', ['name' => 'Game'])->assertSessionHasNoErrors();
        $game = CatalogService::where('store_id', $store->id)->where('name', 'Game')->firstOrFail();
        $this->patch($base.'/categories/'.$category->id, ['name' => 'Promo Bulanan', 'service_id' => $game->id, 'provider' => 'xl'])->assertSessionHasErrors('provider');
        $this->assertSame($service->id, $category->fresh()->service_id);
        $this->patch($base.'/categories/'.$category->id, ['name' => 'Promo Bulanan', 'service_id' => $game->id, 'provider' => ''])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey($category->id, $store->fresh()->branding['category_providers']);
        $this->patch($base.'/categories/'.$category->id, ['name' => 'Promo Bulanan', 'service_id' => $service->id, 'provider' => 'tri'])->assertSessionHasNoErrors();
        $this->delete($base.'/categories/'.$category->id)->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey($category->id, $store->fresh()->branding['category_providers']);
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

    public function test_category_can_move_services_with_products_settings_and_inherited_prices(): void
    {
        $store = $this->shop();
        $first = $this->product($store, 'Aplikasi Premium', 'Paket Data XL');
        $second = $this->product($store, 'Aplikasi Premium', 'Paket Data XL');
        foreach ([$first, $second] as $product) {
            ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
            PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'ready', 'cost' => 10000]);
        }
        $second->update(['markup' => '5000', 'auto_update' => false]);
        $category = $first->taxonomyCategory;
        $oldService = $category->service;
        $next = CatalogService::create(['store_id' => $store->id, 'name' => 'Pulsa', 'name_key' => 'pulsa']);
        PricingRule::create(['store_id' => $store->id, 'scope' => 'service', 'key' => 'aplikasi premium', 'markup' => '10%', 'auto_update' => true]);
        PricingRule::create(['store_id' => $store->id, 'scope' => 'service', 'key' => 'pulsa', 'markup' => '20%', 'auto_update' => true]);
        PricingRule::create(['store_id' => $store->id, 'scope' => 'category', 'key' => 'paket data xl', 'markup' => null, 'auto_update' => null]);
        $store->update(['branding' => ['category_destinations' => [$category->id => ['type' => 'auto', 'columns' => 'auto']], 'category_images' => [$category->id => 'https://example.test/xl.webp']]]);
        $this->actingAs($store->owner)->patch('/stores/'.$store->id.'/categories/'.$category->id, ['name' => 'Paket Data XL', 'service_id' => $next->id])->assertSessionHasNoErrors();
        $this->assertSame($next->id, $category->fresh()->service_id);
        foreach ([$first, $second] as $product) {
            $this->assertSame($category->id, $product->fresh()->category_id);
            $this->assertSame('Pulsa', $product->fresh()->service);
        }
        $this->assertSame(12000, $first->fresh()->sale_price);
        $this->assertSame(15000, $second->fresh()->sale_price);
        $this->assertSame('5000', $second->fresh()->markup);
        $this->assertDatabaseHas('pricing_rules', ['store_id' => $store->id, 'scope' => 'service', 'key' => 'aplikasi premium', 'markup' => '10%']);
        $this->assertDatabaseHas('pricing_rules', ['store_id' => $store->id, 'scope' => 'category', 'key' => 'paket data xl']);
        $this->assertSame(0, $oldService->products()->count());
        $this->assertSame('https://example.test/xl.webp', $store->fresh()->branding['category_images'][$category->id]);
        $this->assertSame('phone', app(CategoryDestination::class)->profile($store->fresh(), $category->fresh(), 'premium')['type']);
    }

    public function test_moving_categories_rejects_foreign_services_and_name_collisions_without_partial_changes(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $category = $product->taxonomyCategory;
        $conflict = $this->product($store, 'Layanan tujuan', 'Telkomsel')->taxonomyCategory;
        $foreign = $this->product($this->shop())->taxonomyCategory;
        $url = '/stores/'.$store->id.'/categories/'.$category->id;
        $this->actingAs($store->owner)->patch($url, ['name' => 'Telkomsel', 'service_id' => $foreign->service_id])->assertSessionHasErrors('service_id');
        $this->patch($url, ['name' => 'Telkomsel', 'service_id' => $conflict->service_id])->assertSessionHasErrors('name');
        $this->assertSame('Pulsa', $product->fresh()->service);
        $this->assertSame($category->service_id, $category->fresh()->service_id);
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

    public function test_picker_scroll_loads_five_then_ten_without_skipping_services_or_categories(): void
    {
        $store = $this->shop();
        for ($i = 0; $i < 23; $i++) {
            $number = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $this->product($store, 'Layanan '.$number, 'Kategori '.$number);
            $this->product($store, 'Layanan 00', 'Kategori '.$number);
        }
        $foreign = $this->product($this->shop(), 'Layanan rahasia', 'Kategori rahasia');
        $service = CatalogService::where('store_id', $store->id)->where('name', 'Layanan 00')->firstOrFail();
        $base = '/stores/'.$store->id.'/catalog-structure/options?';
        $this->actingAs($store->owner);
        foreach (['type=services', 'type=categories&service_id='.$service->id, 'type=pricing_categories'] as $filter) {
            $ids = [];
            foreach ([[0, 5, 5, 5], [5, 10, 10, 15], [15, 10, 8, null]] as [$offset, $size, $count, $next]) {
                $response = $this->getJson($base.$filter.'&offset='.$offset.'&per_page='.$size)->assertOk()
                    ->assertJsonCount($count, 'items')->assertJsonPath('pagination.total', 23)->assertJsonPath('pagination.next_offset', $next)
                    ->assertDontSee('rahasia');
                $ids = [...$ids, ...array_column($response->json('items'), 'id')];
            }
            $this->assertCount(23, array_unique($ids));
            $this->getJson($base.$filter.'&offset=0&per_page=5&q=22')->assertJsonCount(1, 'items')->assertJsonPath('pagination.next_offset', null);
            $this->getJson($base.$filter.'&offset=23&per_page=10')->assertJsonCount(0, 'items')->assertJsonPath('pagination.next_offset', null);
        }
        $this->getJson($base.'type=services&offset=-1')->assertUnprocessable();
        $this->getJson($base.'type=services&offset=1000001')->assertUnprocessable();
        $this->getJson($base.'type=categories&offset=0&service_id='.$foreign->service_id)->assertNotFound();
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
