<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
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

class StoreDesignTest extends TestCase
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
            'name' => 'Design shop', 'slug' => 'design-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy',
            'template' => 'nebula', 'status' => 'active', 'expires_at' => now()->addMonth(), 'branding' => ['existing' => 'preserved']]);
    }

    private function payload(Store $store): array
    {
        return ['revision' => (int) ($store->fresh()->branding['design_revision'] ?? 0), 'design' => app(StorefrontDesign::class)->resolve($store->fresh())];
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('banner.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jMhkAAAAASUVORK5CYII='));
    }

    private function product(Store $store, string $name = 'Game voucher', bool $ready = true): CatalogProduct
    {
        $product = CatalogProduct::factory()->create(['store_id' => $store->id, 'name' => $name, 'category' => 'Different category', 'service' => 'Game', 'kind' => 'premium', 'active' => true, 'sale_price' => 15000]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock']);
        if ($ready) {
            PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'ready', 'cost' => 10000]);
        }

        return $product;
    }

    public function test_owner_publishes_uploaded_banner_logo_and_design_without_domain_pinning(): void
    {
        $store = $this->shop();
        $data = $this->payload($store);
        $data['design']['accent'] = '#1a9c88';
        $data['design']['banners'][0]['title'] = 'Weekend gaming';
        $data['logo_file'] = $this->image();
        $data['banner_files']['default-banner'] = $this->image();
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/design', $data)->assertSessionHasNoErrors();
        $branding = $store->fresh()->branding;
        $this->assertSame('preserved', $branding['existing']);
        $this->assertSame(1, $branding['design_revision']);
        $design = $branding['design'];
        $this->assertStringStartsWith('/storefront-media/'.$store->id.'/', $design['logo']);
        foreach (app(StorefrontDesign::class)->assets($design, $store->id) as $file) {
            Storage::disk('local')->assertExists($file);
        }
        $this->get('http://'.$store->slug.'.localhost'.$design['logo'])->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('http://'.$store->slug.'.localhost/')->assertInertia(fn (Assert $page) => $page->where('store.branding.design.accent', '#1a9c88')->where('store.branding.design.banners.0.title', 'Weekend gaming'));
        $this->get('http://localhost/owner/stores/'.$store->id.'/manage?tab=design')->assertOk()->assertInertia(fn (Assert $page) => $page->where('designRevision', 1)->where('design.logo', $design['logo']));
    }

    public function test_asset_cleanup_retains_shared_references_until_the_last_usage_is_removed(): void
    {
        $store = $this->shop();
        $data = $this->payload($store);
        $data['logo_file'] = $this->image();
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/design', $data)->assertSessionHasNoErrors();
        $data = $this->payload($store);
        $logo = $data['design']['logo'];
        $data['design']['banners'][0]['image'] = $logo;
        $this->post('/stores/'.$store->id.'/design', $data)->assertSessionHasNoErrors();
        $data = $this->payload($store);
        $data['design']['logo'] = null;
        $this->post('/stores/'.$store->id.'/design', $data)->assertSessionHasNoErrors();
        $this->get($logo)->assertOk();
        $data = $this->payload($store);
        $data['design']['banners'] = [];
        $this->post('/stores/'.$store->id.'/design', $data)->assertSessionHasNoErrors();
        $this->get($logo)->assertNotFound();
        $this->assertCount(0, Storage::disk('local')->allFiles('storefront/'.$store->id));
    }

    public function test_curated_products_keep_owner_order_and_real_checkout_prices_without_category_changes(): void
    {
        $store = $this->shop();
        $store->update(['branding' => [...$store->branding, 'gaming_flash_ends_at' => now()->subMinute()->toIso8601String()]]);
        $first = $this->product($store, 'Alpha');
        $second = $this->product($store, 'Zulu');
        $empty = $this->product($store, 'Empty', false);
        $data = $this->payload($store);
        $data['design']['flash_product_ids'] = [$second->id, $empty->id, $first->id];
        $data['design']['trending_product_ids'] = [$second->id, $first->id];
        $data['design']['trending_mode'] = 'manual';
        $data['design']['flash_ends_at'] = null;
        $data['design']['sections'] = array_reverse($data['design']['sections']);
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/design', $data)->assertSessionHasNoErrors();
        $this->get('http://'.$store->slug.'.localhost/')->assertInertia(fn (Assert $page) => $page->has('gaming.flash_sale', 2)
            ->where('gaming.flash_sale.0.id', $second->id)->where('gaming.flash_sale.1.id', $first->id)->where('gaming.flash_sale.0.price', 15000)
            ->where('gaming.trending.0.id', $second->id)->where('store.branding.design.sections.0.key', 'guide'));
        $this->assertSame('Different category', $first->fresh()->category);
        $this->get('http://'.$store->slug.'.localhost/checkout?product='.$second->id)->assertInertia(fn (Assert $page) => $page->where('product.price', 15000));
    }

    public function test_design_product_picker_is_paginated_searchable_scoped_and_has_no_private_fields(): void
    {
        $store = $this->shop();
        CatalogProduct::factory()->count(16)->create(['store_id' => $store->id, 'active' => true, 'category' => 'Needle', 'name' => 'Voucher']);
        $this->product($this->shop(), 'Foreign voucher');
        $url = '/stores/'.$store->id.'/design/products';
        $this->actingAs($store->owner)->getJson($url.'?q=Needle&per_page=5&page=2')->assertOk()
            ->assertJsonPath('pagination.total', 16)->assertJsonPath('pagination.current_page', 2)->assertJsonCount(5, 'items')
            ->assertJsonMissingPath('items.0.cost')->assertJsonMissingPath('items.0.mappings')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($url.'?q=Foreign')->assertJsonCount(0, 'items');
    }

    public function test_only_owner_and_authorized_staff_of_the_same_store_can_publish(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $url = '/stores/'.$store->id.'/design';
        $data = $this->payload($store);
        $this->actingAs($other->owner)->post($url, $data)->assertForbidden();
        auth('web')->logout();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Designer', 'username' => 'designstaff', 'email' => 'design@example.test', 'password' => 'test-password-123', 'permissions' => ['web.manage']]);
        $this->actingAs($staff, 'staff')->post($url, $data)->assertSessionHasNoErrors();
        $staff->update(['permissions' => ['products.view']]);
        $this->post($url, $this->payload($store))->assertForbidden();
        $this->getJson($url.'/products')->assertForbidden();
    }

    public function test_foreign_products_images_injected_links_and_disabled_catalog_are_rejected(): void
    {
        $store = $this->shop();
        $foreign = $this->product($this->shop());
        $data = $this->payload($store);
        $url = '/stores/'.$store->id.'/design';
        $this->actingAs($store->owner);
        $invalid = $data;
        $invalid['design']['flash_product_ids'] = [$foreign->id];
        $this->post($url, $invalid)->assertSessionHasErrors('design.flash_product_ids.0');
        foreach (['javascript:alert(1)', '//evil.test', '/\\evil.test'] as $href) {
            $invalid = $data;
            $invalid['design']['banners'][0]['href'] = $href;
            $this->post($url, $invalid)->assertSessionHasErrors('design.banners.0.href');
        }
        $invalid = $data;
        $invalid['design']['logo'] = '/storefront-media/'.$foreign->store_id.'/'.str_repeat('a', 40).'.png';
        $this->post($url, $invalid)->assertSessionHasErrors('design.logo');
        $invalid = $data;
        $invalid['design']['sections'][4]['enabled'] = false;
        $this->post($url, $invalid)->assertSessionHasErrors('design.sections');
        $invalid = $data;
        $invalid['logo_file'] = UploadedFile::fake()->createWithContent('logo.svg', '<svg><script>alert(1)</script></svg>');
        $this->post($url, $invalid)->assertSessionHasErrors('logo_file');
        $invalid = $data;
        $invalid['design']['powered_by'] = false;
        $this->post($url, $invalid)->assertSessionHasErrors('design');
        $this->assertArrayNotHasKey('design_revision', $store->fresh()->branding);
    }

    public function test_stale_editor_cannot_overwrite_another_publish_or_leave_uploaded_files(): void
    {
        $store = $this->shop();
        $data = $this->payload($store);
        $url = '/stores/'.$store->id.'/design';
        $this->actingAs($store->owner)->post($url, $data)->assertSessionHasNoErrors();
        $data['logo_file'] = $this->image();
        $this->post($url, $data)->assertSessionHasErrors('revision');
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->assertSame(1, $store->fresh()->branding['design_revision']);
    }

    public function test_multipart_empty_lists_and_false_flags_are_preserved_and_bad_schedule_is_rejected(): void
    {
        $store = $this->shop();
        $data = $this->payload($store);
        unset($data['design']['banners'], $data['design']['flash_product_ids'], $data['design']['trending_product_ids']);
        $data['revision'] = '0';
        $data['design']['sections'][0]['enabled'] = '0';
        $url = '/stores/'.$store->id.'/design';
        $this->actingAs($store->owner)->post($url, $data, ['Content-Type' => 'multipart/form-data; boundary=test'])->assertSessionHasNoErrors();
        $saved = $store->fresh()->branding['design'];
        $this->assertSame([], $saved['banners']);
        $this->assertSame([], $saved['flash_product_ids']);
        $this->assertFalse($saved['sections'][0]['enabled']);
        $data = $this->payload($store);
        $data['design']['flash_starts_at'] = now()->addDay()->toIso8601String();
        $data['design']['flash_ends_at'] = now()->toIso8601String();
        $this->post($url, $data)->assertSessionHasErrors('design.flash_ends_at');
    }

    public function test_banner_destinations_carousel_and_palette_publish_to_storefront_and_checkout(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $product->update(['category' => 'Games & Voucher']);
        $data = $this->payload($store);
        $banner = $data['design']['banners'][0];
        $data['design']['banners'] = [
            [...$banner, 'id' => 'category', 'link_type' => 'category', 'category' => $product->category, 'href' => null],
            [...$banner, 'id' => 'external', 'link_type' => 'url', 'href' => 'https://example.com/promo?code=game'],
            [...$banner, 'id' => 'none', 'link_type' => 'none', 'href' => null, 'button' => null],
        ];
        $data['design']['colors']['background'] = '#edf8f2';
        $data['design']['colors']['secondary'] = '#b66a17';
        $data['design']['carousel'] = ['autoplay' => '0', 'interval' => '8'];
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/design', $data)->assertSessionHasNoErrors();
        $design = $store->fresh()->branding['design'];
        $this->assertSame('/?category=Games%20%26%20Voucher#catalog', $design['banners'][0]['href']);
        $this->assertSame('https://example.com/promo?code=game', $design['banners'][1]['href']);
        $this->assertSame('', $design['banners'][2]['href']);
        $this->assertSame(['autoplay' => false, 'interval' => 8], $design['carousel']);
        $this->get('http://'.$store->slug.'.localhost/?category=Games%20%26%20Voucher')->assertRedirect('/kategori/'.$product->category_id);
        $this->get('http://'.$store->slug.'.localhost/kategori/'.$product->category_id)->assertInertia(fn (Assert $page) => $page
            ->where('category.name', 'Games & Voucher')->has('products', 1)->where('store.branding.design.colors.background', '#edf8f2'));
        $this->get('http://'.$store->slug.'.localhost/checkout?product='.$product->id)->assertInertia(fn (Assert $page) => $page
            ->where('store.branding.design.colors.secondary', '#b66a17')->where('store.branding.design.carousel.interval', 8));
    }

    public function test_invalid_palette_intervals_links_and_foreign_category_are_rejected(): void
    {
        $store = $this->shop();
        $foreign = $this->product($this->shop());
        $foreign->update(['category' => 'Foreign category']);
        $data = $this->payload($store);
        $this->actingAs($store->owner);
        $url = '/stores/'.$store->id.'/design';
        foreach ([2, 31, 4.5] as $interval) {
            $invalid = $data;
            $invalid['design']['carousel']['interval'] = $interval;
            $this->post($url, $invalid)->assertSessionHasErrors('design.carousel.interval');
        }
        $invalid = $data;
        $invalid['design']['colors']['background'] = 'red;display:none';
        $this->post($url, $invalid)->assertSessionHasErrors('design.colors.background');
        $invalid = $data;
        $invalid['design']['banners'][0] = [...$invalid['design']['banners'][0], 'link_type' => 'category', 'category' => $foreign->category];
        $this->post($url, $invalid)->assertSessionHasErrors('design.banners.0.category');
        foreach (['https://user:pass@example.com', 'http://example.com', "https://example.com/\npath"] as $href) {
            $invalid = $data;
            $invalid['design']['banners'][0]['href'] = $href;
            $this->post($url, $invalid)->assertSessionHasErrors('design.banners.0.href');
        }
        $this->assertArrayNotHasKey('design', $store->fresh()->branding);
    }

    public function test_category_choices_use_active_products_and_remain_scoped_searchable_and_bounded(): void
    {
        $store = $this->shop();
        foreach (range(1, 12) as $index) {
            CatalogProduct::factory()->create(['store_id' => $store->id, 'category' => 'Category '.$index, 'active' => true]);
        }
        CatalogProduct::factory()->create(['store_id' => $store->id, 'category' => 'Hidden category', 'active' => false]);
        $this->product($this->shop())->update(['category' => 'Foreign category']);
        $url = '/stores/'.$store->id.'/design/categories';
        $this->actingAs($store->owner)->getJson($url.'?q=Category&per_page=5&page=2')->assertOk()
            ->assertJsonPath('pagination.total', 12)->assertJsonCount(5, 'items');
        $this->getJson($url.'?q=Foreign')->assertJsonCount(0, 'items');
        $this->getJson($url.'?q=Hidden')->assertJsonCount(0, 'items');
        $this->actingAs($this->shop()->owner)->getJson($url)->assertForbidden();
    }

    public function test_legacy_design_receives_editor_defaults_without_rewriting_published_branding(): void
    {
        $store = $this->shop();
        $legacy = $this->payload($store)['design'];
        unset($legacy['colors'], $legacy['carousel']);
        $store->update(['branding' => ['design' => $legacy]]);
        $resolved = app(StorefrontDesign::class)->resolve($store);
        $this->assertSame(['autoplay' => true, 'interval' => 5], $resolved['carousel']);
        $this->assertSame('#0c101c', $resolved['colors']['background']);
        $this->assertSame($legacy, $store->fresh()->branding['design']);
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/design', ['revision' => 0, 'design' => $legacy])->assertSessionHasNoErrors();
    }

    public function test_welcome_ad_upload_and_customer_service_settings_are_published_and_cleaned_up(): void
    {
        $store = $this->shop();
        $data = $this->payload($store);
        $data['design']['welcome_ad']['enabled'] = '1';
        $data['design']['welcome_ad']['href'] = 'https://example.com/promo';
        $data['design']['customer_service'] = ['enabled' => '1', 'phone' => '081234567890', 'label' => 'Bantuan toko', 'message' => 'Halo, saya butuh bantuan.'];
        $data['ad_file'] = $this->image();
        $url = 'http://localhost/stores/'.$store->id.'/design';
        $this->actingAs($store->owner)->post($url, $data)->assertSessionHasNoErrors();
        $design = $store->fresh()->branding['design'];
        $this->assertTrue($design['welcome_ad']['enabled']);
        $this->assertTrue($design['customer_service']['enabled']);
        $this->get($design['welcome_ad']['image'])->assertOk();
        $this->get('http://'.$store->slug.'.localhost/')->assertInertia(fn (Assert $page) => $page
            ->where('store.branding.design.customer_service.phone', '081234567890')
            ->where('store.branding.design.welcome_ad.href', 'https://example.com/promo'));
        $data = $this->payload($store);
        $data['design']['welcome_ad']['image'] = null;
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertNull($store->fresh()->branding['design']['welcome_ad']['image']);
        $this->get($design['welcome_ad']['image'])->assertNotFound();
    }

    public function test_welcome_ad_links_and_customer_service_numbers_are_validated(): void
    {
        $store = $this->shop();
        $data = $this->payload($store);
        $url = '/stores/'.$store->id.'/design';
        $this->actingAs($store->owner);
        $invalid = $data;
        $invalid['design']['welcome_ad']['href'] = 'javascript:alert(1)';
        $this->post($url, $invalid)->assertSessionHasErrors('design.welcome_ad.href');
        $invalid = $data;
        $invalid['design']['customer_service']['enabled'] = true;
        $this->post($url, $invalid)->assertSessionHasErrors('design.customer_service.phone');
        $invalid['design']['customer_service']['phone'] = 'javascript:alert(1)';
        $this->post($url, $invalid)->assertSessionHasErrors('design.customer_service.phone');
        $invalid = $data;
        $invalid['ad_file'] = UploadedFile::fake()->createWithContent('ad.svg', '<svg><script>alert(1)</script></svg>');
        $this->post($url, $invalid)->assertSessionHasErrors('ad_file');
        $this->assertArrayNotHasKey('design', $store->fresh()->branding);
    }
}
