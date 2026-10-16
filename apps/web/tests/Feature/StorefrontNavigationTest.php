<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Support\StorefrontDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StorefrontNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'name' => 'Game Shop', 'slug' => 'game-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy',
            'template' => 'nebula', 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function product(Store $store, string $category = 'Mobile Legends'): CatalogProduct
    {
        return CatalogProduct::factory()->create(['store_id' => $store->id, 'service' => 'Games', 'category' => $category, 'active' => true]);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('category.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jMhkAAAAASUVORK5CYII='));
    }

    public function test_nebula_home_lists_paginated_store_categories_instead_of_products(): void
    {
        $store = $this->shop();
        for ($i = 0; $i < 12; $i++) {
            $this->product($store, 'Game '.$i);
        }
        $this->product($store, 'Hidden')->update(['active' => false]);
        $this->product($this->shop(), 'Foreign');
        $this->get('http://'.$store->slug.'.localhost/?per_page=5&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('products', 0)->has('categoryCards', 5)->where('categoryPagination.total', 12)->where('categoryPagination.current_page', 2));
        $this->get('http://'.$store->slug.'.localhost/?q=Game%2011')->assertInertia(fn (Assert $page) => $page
            ->has('categoryCards', 1)->where('categoryCards.0.name', 'Game 11')->where('categoryCards.0.products_count', 1));
    }

    public function test_category_checkout_is_public_scoped_paginated_and_supports_flash_product_selection(): void
    {
        $store = $this->shop();
        $first = $this->product($store);
        CatalogProduct::factory()->count(12)->create(['store_id' => $store->id, 'category_id' => $first->category_id, 'active' => true]);
        $different = $this->product($store, 'Different');
        $foreign = $this->product($this->shop());
        $url = 'http://'.$store->slug.'.localhost/kategori/'.$first->category_id;
        $this->get($url.'?product='.$first->id.'&per_page=5&page=2')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Storefront/Category')->where('customer', null)->where('selectedProduct.id', $first->id)
            ->where('category.id', $first->category_id)->has('products', 5)->where('pagination.total', 13)
            ->missing('products.0.cost')->missing('products.0.candidates'));
        $this->get($url.'?product='.$different->id)->assertInertia(fn (Assert $page) => $page->where('selectedProduct', null));
        $this->get($url.'?product='.$foreign->id)->assertInertia(fn (Assert $page) => $page->where('selectedProduct', null));
        $this->get('http://'.$store->slug.'.localhost/kategori/'.$foreign->category_id)->assertNotFound();
        $store->update(['expires_at' => now()->subDays(8), 'status' => 'expired']);
        $this->get($url)->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_category_images_can_be_uploaded_replaced_and_removed_without_affecting_other_settings(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $category = $product->taxonomyCategory;
        $url = 'http://localhost/stores/'.$store->id.'/categories/'.$category->id;
        $this->actingAs($store->owner)->post($url, ['_method' => 'patch', 'name' => $category->name, 'image_file' => $this->image()])->assertSessionHasNoErrors();
        $image = $store->fresh()->branding['category_images'][$category->id];
        $path = 'storefront/'.$store->id.'/'.basename($image);
        Storage::disk('local')->assertExists($path);
        $this->get($image)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('http://'.$store->slug.'.localhost/')->assertInertia(fn (Assert $page) => $page->where('categoryCards.0.image', $image));
        $this->get('http://'.$store->slug.'.localhost/kategori/'.$category->id)->assertInertia(fn (Assert $page) => $page->where('category.image', $image));
        $this->patch($url, ['name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame($image, $store->fresh()->branding['category_images'][$category->id]);
        $this->patch($url, ['name' => 'Renamed', 'image_url' => 'https://example.test/game.webp'])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
        $this->patch($url, ['name' => 'Renamed', 'remove_image' => true])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey($category->id, $store->fresh()->branding['category_images']);
        $this->patch($url, ['name' => 'Renamed', 'image_url' => 'javascript:alert(1)'])->assertSessionHasErrors('image_url');
        $this->post($url, ['_method' => 'patch', 'name' => 'Renamed', 'image_file' => UploadedFile::fake()->create('fake.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('image_file');
        $this->assertDatabaseHas('catalog_products', ['id' => $product->id, 'category_id' => $category->id, 'category' => 'Renamed']);
    }

    public function test_category_image_permissions_and_foreign_store_boundaries_are_enforced(): void
    {
        $store = $this->shop();
        $category = $this->product($store)->taxonomyCategory;
        $foreign = $this->product($this->shop())->taxonomyCategory;
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'test-staff', 'email' => 'staff@example.test', 'password' => 'fixture-password', 'active' => true, 'permissions' => ['products.view']]);
        $this->actingAs($staff, 'staff')->patch('/stores/'.$store->id.'/categories/'.$category->id, ['name' => $category->name, 'image_url' => 'https://example.test/game.webp'])->assertForbidden();
        $staff->update(['permissions' => ['products.manage']]);
        $this->patch('/stores/'.$store->id.'/categories/'.$category->id, ['name' => $category->name, 'image_url' => 'https://example.test/game.webp'])->assertSessionHasNoErrors();
        $this->patch('/stores/'.$store->id.'/categories/'.$foreign->id, ['name' => $category->name, 'image_file' => $this->image()])->assertNotFound();
        Storage::disk('local')->assertDirectoryEmpty('storefront');
    }

    public function test_category_image_shared_with_the_logo_is_deleted_only_after_its_last_reference_is_removed(): void
    {
        $store = $this->shop();
        $category = $this->product($store)->taxonomyCategory;
        $url = 'http://localhost/stores/'.$store->id.'/categories/'.$category->id;
        $this->actingAs($store->owner)->post($url, ['_method' => 'patch', 'name' => $category->name, 'image_file' => $this->image()])->assertSessionHasNoErrors();
        $image = $store->fresh()->branding['category_images'][$category->id];
        $path = 'storefront/'.$store->id.'/'.basename($image);
        $design = app(StorefrontDesign::class)->resolve($store->fresh());
        $design['logo'] = $image;
        $this->post('http://localhost/stores/'.$store->id.'/design', ['revision' => 0, 'design' => $design])->assertSessionHasNoErrors();
        $design['logo'] = null;
        $this->post('http://localhost/stores/'.$store->id.'/design', ['revision' => 1, 'design' => $design])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($path);
        $design['logo'] = $image;
        $this->post('http://localhost/stores/'.$store->id.'/design', ['revision' => 2, 'design' => $design])->assertSessionHasNoErrors();
        $this->patch($url, ['name' => $category->name, 'remove_image' => true])->assertSessionHasNoErrors();
        Storage::disk('local')->assertExists($path);
        $design['logo'] = null;
        $this->post('http://localhost/stores/'.$store->id.'/design', ['revision' => 3, 'design' => $design])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_calculator_and_leaderboard_pages_are_public_without_disclosing_customer_data(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'PRIVATE-NAME', 'phone' => '6281234567890', 'email' => 'private@example.test']);
        foreach (['success', 'failed'] as $status) {
            CustomerOrder::create(['store_id' => $store->id, 'product_id' => $product->id, 'customer_id' => $customer->id, 'number' => 'DT'.Str::ulid(), 'idempotency_key' => Str::uuid(), 'request_hash' => str_repeat('a', 64), 'channel' => 'web', 'purpose' => 'product', 'status' => $status, 'payment_status' => 'paid', 'gateway' => 'wallet', 'phone' => $customer->phone, 'destination' => 'PRIVATE-ID', 'snapshot' => ['name' => $product->name], 'amount' => 15000, 'total' => 17000, 'fee' => 2000, 'expires_at' => now()->addHour(), 'completed_at' => now()]);
        }
        $this->get('http://'.$store->slug.'.localhost/leaderboard?period=day')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Storefront/Leaderboard')->where('period', 'day')->has('ranking', 1)->where('ranking.0.amount', 15000)
            ->where('ranking.0.purchases', 1)->missing('ranking.0.customer_id')->missing('ranking.0.phone'))
            ->assertDontSee('PRIVATE-NAME')->assertDontSee('private@example.test')->assertDontSee('6281234567890');
        foreach (['winrate', 'magic-wheel', 'zodiac'] as $calculator) {
            $this->get('http://'.$store->slug.'.localhost/calculator/'.$calculator)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Storefront/Calculator')->where('calculator', $calculator));
        }
        $this->get('http://'.$store->slug.'.localhost/calculator/unknown')->assertNotFound();
    }

    public function test_manual_trending_uses_categories_and_rejects_foreign_category_ids(): void
    {
        $store = $this->shop();
        $one = $this->product($store);
        $two = $this->product($store, 'Free Fire');
        $foreign = $this->product($this->shop());
        $design = app(StorefrontDesign::class)->resolve($store);
        $design['trending_mode'] = 'manual';
        $design['trending_category_ids'] = [$two->category_id, $one->category_id];
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/design', ['revision' => 0, 'design' => $design])->assertSessionHasNoErrors();
        $this->get('http://'.$store->slug.'.localhost/')->assertInertia(fn (Assert $page) => $page
            ->has('gaming.trending', 2)->where('gaming.trending.0.category_id', $two->category_id)->where('gaming.trending.1.category_id', $one->category_id));
        $design['trending_category_ids'] = [$foreign->category_id];
        $this->post('http://localhost/stores/'.$store->id.'/design', ['revision' => 1, 'design' => $design])->assertSessionHasErrors('design.trending_category_ids.0');
    }
}
