<?php

namespace Tests\Feature;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Support\CatalogAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CatalogImportAllTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function shop(): Store
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved']);

        return Store::create(['owner_id' => $owner->id, 'name' => 'Import Test', 'slug' => 'import-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
    }

    private function connection(Store $store, string $kind = 'digiflazz'): SupplierConnection
    {
        return SupplierConnection::create(['store_id' => $store->id, 'kind' => $kind, 'enabled' => true, 'synced_at' => now()]);
    }

    private function item(SupplierConnection $connection, array $attributes = []): SupplierProduct
    {
        return SupplierProduct::create([...['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'sku' => 'TEST-'.Str::random(10), 'name' => 'Produk Uji', 'kind' => 'ppob', 'mode' => 'prepaid', 'service' => 'Pulsa', 'category' => 'Telkomsel', 'cost' => 10000, 'available' => true, 'observed_at' => now()], ...$attributes]);
    }

    private function url(Store $store, SupplierConnection $connection): string
    {
        return '/stores/'.$store->id.'/sources/'.$connection->id.'/import-all';
    }

    public function test_imported_categories_get_default_logos_but_manual_categories_and_other_stores_remain_untouched(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store);
        $otherStore = $this->shop();
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/services', ['name' => 'Pulsa'])->assertSessionHasNoErrors();
        $service = CatalogService::where('store_id', $store->id)->firstOrFail();
        $this->post('/stores/'.$store->id.'/categories', ['name' => 'Telkomsel', 'service_id' => $service->id])->assertSessionHasNoErrors();
        $manual = CatalogCategory::where('service_id', $service->id)->firstOrFail();
        $this->assertEmpty($store->fresh()->branding['category_images'] ?? []);
        $first = $this->item($connection, ['category' => 'XL']);
        $unknown = $this->item($connection, ['category' => 'Merek Tidak Dikenal']);
        $this->item($connection, ['category' => 'Telkomsel']);
        $this->post('/stores/'.$store->id.'/products/import', ['ids' => [$first->id, $unknown->id]])->assertSessionHasNoErrors();
        $category = CatalogProduct::where('category', 'XL')->firstOrFail()->category_id;
        $this->postJson($this->url($store, $connection))->assertOk();
        $this->assertSame([$category => '/brand/catalog/xl.svg'], $store->fresh()->branding['category_images']);
        $this->assertArrayNotHasKey($manual->id, $store->fresh()->branding['category_images']);
        $this->assertEmpty($otherStore->fresh()->branding['category_images'] ?? []);
        $this->assertDatabaseCount('catalog_products', 3);
    }

    public function test_reimport_preserves_custom_artwork_and_does_not_restore_a_removed_default_logo(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store, 'hanshop');
        $first = $this->item($connection, ['category' => 'Spotify Premium', 'service' => 'Aplikasi Premium', 'kind' => 'premium']);
        $url = $this->url($store, $connection);
        $this->actingAs($store->owner)->postJson($url)->assertOk()->assertJsonPath('imported', 1);
        $categoryId = CatalogProduct::firstOrFail()->category_id;
        $this->assertSame('/brand/catalog/spotify.svg', $store->fresh()->branding['category_images'][$categoryId]);
        $categoryUrl = '/stores/'.$store->id.'/categories/'.$categoryId;
        $this->patch($categoryUrl, ['name' => 'Spotify Premium', 'image_url' => 'https://example.com/custom.png'])->assertSessionHasNoErrors();
        $this->item($connection, ['category' => 'Spotify Premium', 'service' => 'Aplikasi Premium', 'kind' => 'premium']);
        $this->postJson($url)->assertOk()->assertJsonPath('imported', 1);
        $this->assertSame('https://example.com/custom.png', $store->fresh()->branding['category_images'][$categoryId]);
        $this->patch($categoryUrl, ['name' => 'Spotify Premium', 'remove_image' => true])->assertSessionHasNoErrors();
        $this->item($connection, ['category' => 'Spotify Premium', 'service' => 'Aplikasi Premium', 'kind' => 'premium']);
        $this->postJson($url)->assertOk()->assertJsonPath('imported', 1);
        $this->assertArrayNotHasKey($categoryId, $store->fresh()->branding['category_images']);
        $this->assertFileExists(public_path('brand/catalog/spotify.svg'));
        $this->post('/stores/'.$store->id.'/products/import', ['ids' => [$first->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('catalog_products', 3);
    }

    public function test_backfill_adds_logos_to_legacy_imports_without_overwriting_manual_custom_or_removed_images(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store);
        $categories = [];
        foreach (['Telkomsel', 'Spotify', 'Netflix', 'Canva', 'Kategori Bebas'] as $name) {
            $product = CatalogProduct::create(['store_id' => $store->id, 'name' => $name, 'code' => Str::random(8), 'service' => 'Produk', 'category' => $name, 'kind' => 'ppob', 'sale_price' => 12000, 'active' => true]);
            $item = $this->item($connection, ['category' => $name]);
            ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'supplier_product_id' => $item->id, 'source' => 'digiflazz', 'source_key' => 'item:'.$item->id]);
            $categories[$name] = $product->category_id;
        }
        $store->update(['branding' => ['primary' => '#123456', 'category_images' => [$categories['Canva'] => 'https://example.com/own.png'],
            'category_image_preferences' => [$categories['Spotify'] => 'manual', $categories['Netflix'] => 'none']]]);
        $this->artisan('digitaltoko:category-logos', ['--store' => $store->id])->assertSuccessful();
        $this->assertCount(1, $store->fresh()->branding['category_images']);
        $this->artisan('digitaltoko:category-logos', ['--store' => $store->slug, '--apply' => true])->assertSuccessful();
        $branding = $store->fresh()->branding;
        $this->assertSame('/brand/catalog/telkomsel.svg', $branding['category_images'][$categories['Telkomsel']]);
        $this->assertSame('https://example.com/own.png', $branding['category_images'][$categories['Canva']]);
        $this->assertCount(2, $branding['category_images']);
        $this->assertSame('#123456', $branding['primary']);
        $this->artisan('digitaltoko:category-logos', ['--store' => $store->id, '--apply' => true])->assertSuccessful();
        $this->assertSame($branding, $store->fresh()->branding);
    }

    public function test_all_import_spans_batches_ignores_table_filters_and_is_repeatable_without_changing_existing_products(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store);
        for ($i = 0; $i < 107; $i++) {
            $this->item($connection, ['sku' => 'SKU-'.$i, 'available' => $i % 2 === 0, 'mode' => $i % 2 === 0 ? 'prepaid' : 'pasca', 'kind' => $i % 2 === 0 ? 'ppob' : 'postpaid']);
        }
        $hanshop = $this->connection($store, 'hanshop');
        $this->item($hanshop);
        $this->item($this->connection($this->shop()));
        PricingRule::create(['store_id' => $store->id, 'scope' => 'category', 'key' => 'telkomsel', 'markup' => '10%', 'auto_update' => true]);
        $url = $this->url($store, $connection).'?q=DOES-NOT-EXIST&available=1&mode=prepaid&per_page=5';
        $this->actingAs($store->owner);
        $first = $this->postJson($url)->assertOk()->assertJsonPath('imported', 100)->assertJsonPath('remaining', 7)->assertJsonPath('total', 107)->json();
        $this->assertDatabaseCount('catalog_products', 100);
        $old = CatalogProduct::firstOrFail();
        $this->assertSame(11000, $old->sale_price);
        $this->assertNotNull($old->category_id);
        $old->update(['code' => 'MY-CUSTOM-CODE', 'active' => false, 'sale_price' => 23000, 'markup' => '999', 'auto_update' => false]);
        $new = $this->item($connection);
        $this->postJson($url, ['through' => $first['through']])->assertOk()->assertJsonPath('imported', 7)->assertJsonPath('remaining', 0)->assertJsonPath('total', 107);
        $this->assertDatabaseCount('catalog_products', 107);
        $this->assertDatabaseMissing('product_mappings', ['supplier_product_id' => $new->id]);
        $this->postJson($url)->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('remaining', 0)->assertJsonPath('total', 108);
        $this->postJson($url)->assertOk()->assertJsonPath('imported', 0)->assertJsonPath('remaining', 0);
        $this->assertDatabaseCount('catalog_products', 108);
        $this->assertDatabaseCount('product_mappings', 108);
        $this->assertSame(['code' => 'MY-CUSTOM-CODE', 'active' => false, 'sale_price' => 23000, 'markup' => '999', 'auto_update' => false], $old->fresh()->only(['code', 'active', 'sale_price', 'markup', 'auto_update']));
        $this->assertTrue(CatalogProduct::where('kind', 'postpaid')->exists());
        $this->assertDatabaseMissing('product_mappings', ['supplier_product_id' => SupplierProduct::where('connection_id', $hanshop->id)->value('id')]);
    }

    public function test_hanshop_all_and_selected_import_share_code_mapping_and_empty_catalog_is_clear(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store, 'hanshop');
        $this->actingAs($store->owner)->postJson($this->url($store, $connection))->assertOk()->assertExactJson(['imported' => 0, 'remaining' => 0, 'total' => 0, 'through' => null]);
        $first = $this->item($connection, ['sku' => 'AP / 1', 'kind' => 'premium']);
        $second = $this->item($connection, ['sku' => 'AP / 1', 'mode' => 'pasca', 'kind' => 'postpaid', 'available' => false]);
        $this->post('/stores/'.$store->id.'/products/import', ['ids' => [$first->id]])->assertSessionHasNoErrors();
        $this->postJson($this->url($store, $connection))->assertOk()->assertJsonPath('imported', 1)->assertJsonPath('total', 2)->assertJsonPath('remaining', 0);
        $this->assertDatabaseCount('catalog_products', 2);
        $this->assertSame(2, CatalogProduct::distinct()->count('code'));
        $this->assertEqualsCanonicalizing(['H-AP---1', 'H-AP---1-PASCA'], CatalogProduct::pluck('code')->all());
        $this->assertDatabaseHas('product_mappings', ['supplier_product_id' => $second->id, 'source' => 'hanshop']);
        $this->assertSame('AP / 1', $second->fresh()->sku);
        $this->post('/stores/'.$store->id.'/products/import', ['ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('catalog_products', 2);
    }

    public function test_same_sku_on_two_otomax_suppliers_remains_separate_after_import_and_update(): void
    {
        $store = $this->shop();
        $firstConnection = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => (string) Str::uuid(), 'name' => 'Supplier A', 'enabled' => true]);
        $secondConnection = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => (string) Str::uuid(), 'name' => 'Supplier B', 'enabled' => true]);
        $first = $this->item($firstConnection, ['sku' => 'T10', 'cost' => 10000]);
        $second = $this->item($secondConnection, ['sku' => 'T10', 'cost' => 12000]);
        $this->assertNotSame($first->id, $second->id);
        $url = '/stores/'.$store->id.'/products/import';
        $this->actingAs($store->owner)->post($url, ['ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('catalog_products', ['store_id' => $store->id, 'code' => 'O-T10']);
        $this->assertDatabaseHas('catalog_products', ['store_id' => $store->id, 'code' => 'O-T10-2']);
        $this->assertSame(2, ProductMapping::whereIn('supplier_product_id', [$first->id, $second->id])->distinct()->count('supplier_product_id'));
        $this->post($url, ['ids' => [$first->id, $second->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('catalog_products', 2);
        SupplierProduct::upsert([[...$first->getAttributes(), 'id' => (string) Str::uuid(), 'cost' => 9500]], ['connection_id', 'mode', 'sku'], ['cost']);
        $this->assertSame(9500, $first->fresh()->cost);
        $this->assertSame(12000, $second->fresh()->cost);
        $this->assertDatabaseCount('supplier_products', 2);
    }

    public function test_import_codes_are_short_and_collision_suffixes_keep_length_and_supplier_sku_intact(): void
    {
        $store = $this->shop();
        $digi = $this->connection($store);
        $hanshop = $this->connection($store, 'hanshop');
        $first = $this->item($digi, ['sku' => 'T10']);
        $second = $this->item($hanshop, ['sku' => 'T10']);
        $longSku = str_repeat('A', 70);
        $long = $this->item($digi, ['sku' => $longSku]);
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/products/import', ['ids' => [$first->id, $second->id, $long->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('catalog_products', ['store_id' => $store->id, 'code' => 'D-T10']);
        $this->assertDatabaseHas('catalog_products', ['store_id' => $store->id, 'code' => 'H-T10']);
        ProductMapping::where('supplier_product_id', $first->id)->delete();
        ProductMapping::where('supplier_product_id', $long->id)->delete();
        $this->post('/stores/'.$store->id.'/products/import', ['ids' => [$first->id, $long->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('catalog_products', ['store_id' => $store->id, 'code' => 'D-T10-2']);
        foreach (CatalogProduct::pluck('code') as $code) {
            $this->assertLessThanOrEqual(50, strlen($code));
        }
        $this->assertSame($longSku, $long->fresh()->sku);
        $this->assertSame(5, CatalogProduct::distinct()->count('code'));
    }

    public function test_all_import_enforces_store_staff_package_status_and_connection_permissions_on_every_batch(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store);
        $this->item($connection);
        $url = $this->url($store, $connection);
        $this->actingAs($store->owner)->postJson($this->url($store, $this->connection($this->shop())))->assertNotFound();
        $this->postJson($url, ['through' => 'invalid'])->assertUnprocessable();
        $connection->update(['enabled' => false]);
        $this->postJson($url)->assertForbidden();
        $connection->update(['enabled' => true]);
        $store->update(['subscription' => ['features' => ['hanshop', 'stock']]]);
        $this->assertTrue(CatalogAccess::supports($store, 'digiflazz'));
        $store->update(['subscription' => null, 'status' => 'suspended']);
        $this->postJson($url)->assertForbidden();
        $store->update(['status' => 'active']);
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Reader', 'username' => 'import-reader', 'email' => 'import@test.invalid', 'password' => 'test-only', 'active' => true, 'permissions' => ['products.view']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->postJson($url)->assertForbidden();
        $this->assertDatabaseCount('catalog_products', 0);
        $staff->update(['permissions' => ['products.manage']]);
        $this->postJson($url)->assertOk()->assertJsonPath('imported', 1);
        $staff->update(['active' => false]);
        $this->postJson($url)->assertForbidden();
        $this->assertDatabaseCount('catalog_products', 1);
    }

    public function test_service_and_category_import_use_supplier_scope_across_batches_and_do_not_mix_equal_category_names(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store);
        for ($i = 0; $i < 102; $i++) {
            $this->item($connection, ['service' => 'Paket Data', 'category' => 'XL', 'available' => false]);
        }
        $otherCategory = $this->item($connection, ['service' => 'Paket Data', 'category' => 'Telkomsel']);
        $otherService = $this->item($connection, ['service' => 'Pulsa', 'category' => 'XL']);
        $otherSource = $this->item($this->connection($store, 'hanshop'), ['service' => 'Paket Data', 'category' => 'XL']);
        $url = $this->url($store, $connection);
        $scope = ['scope' => 'category', 'service' => 'Paket Data', 'category' => 'XL'];
        $first = $this->actingAs($store->owner)->postJson($url, $scope)->assertOk()->assertJsonPath('total', 102)->assertJsonPath('imported', 100)->assertJsonPath('remaining', 2)->json();
        $this->postJson($url, [...$scope, 'through' => $first['through']])->assertOk()->assertJsonPath('imported', 2)->assertJsonPath('remaining', 0);
        foreach ([$otherCategory, $otherService, $otherSource] as $item) {
            $this->assertDatabaseMissing('product_mappings', ['supplier_product_id' => $item->id]);
        }
        $this->postJson($url, ['scope' => 'service', 'service' => 'Paket Data'])->assertOk()->assertJsonPath('total', 103)->assertJsonPath('imported', 1)->assertJsonPath('remaining', 0);
        $this->postJson($url, $scope)->assertOk()->assertJsonPath('imported', 0);
        $this->assertDatabaseCount('catalog_products', 103);
        $this->assertDatabaseMissing('product_mappings', ['supplier_product_id' => $otherService->id]);
        $this->postJson($url, ['scope' => 'service'])->assertUnprocessable()->assertJsonValidationErrors('service');
        $this->postJson($url, ['scope' => 'category', 'service' => 'Pulsa'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->postJson($url, ['scope' => 'category', 'service' => 'Pulsa', 'category' => 'Unknown'])->assertUnprocessable()->assertJsonValidationErrors('scope');
        $this->postJson($url, ['scope' => 'unknown'])->assertUnprocessable();
    }

    public function test_import_options_are_searchable_bounded_and_scoped_to_supplier_and_service(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store);
        for ($i = 0; $i < 8; $i++) {
            $this->item($connection, ['service' => 'Layanan '.$i, 'category' => 'Kategori '.$i]);
        }
        $this->item($connection, ['service' => 'Layanan 0', 'category' => 'Kategori 0']);
        $this->item($this->connection($store, 'hanshop'), ['service' => 'Rahasia Hanshop']);
        $foreign = $this->connection($this->shop());
        $this->item($foreign, ['service' => 'Rahasia Toko']);
        $url = '/stores/'.$store->id.'/sources/'.$connection->id.'/import-options';
        $this->actingAs($store->owner)->getJson($url.'?type=services')->assertOk()->assertJsonCount(5, 'items')->assertJsonPath('pagination.total', 8)->assertDontSee('Rahasia');
        $this->getJson($url.'?type=services&q=Layanan%207')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', 'Layanan 7');
        $this->getJson($url.'?type=services&offset=5&per_page=10')->assertOk()->assertJsonCount(3, 'items')->assertJsonPath('items.0.id', 'Layanan 5')->assertJsonPath('pagination.next_offset', null)->assertJsonPath('pagination.total', 8)->assertDontSee('Rahasia');
        $this->getJson($url.'?type=categories&service=Layanan%200')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Kategori 0');
        $this->getJson($url.'?type=categories')->assertUnprocessable();
        $this->getJson('/stores/'.$store->id.'/sources/'.$foreign->id.'/import-options?type=services')->assertNotFound();
        $connection->update(['enabled' => false]);
        $this->getJson($url.'?type=services')->assertForbidden();
    }
}
