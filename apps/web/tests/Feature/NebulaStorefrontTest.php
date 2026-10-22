<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NebulaStorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'name' => 'Game Store', 'slug' => 'game-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy',
            'template' => 'nebula', 'status' => 'active', 'expires_at' => now()->addDays(30), 'branding' => ['accent' => '#abcdef']]);
    }

    public function test_initial_document_loads_the_complete_stylesheet_before_mounting_react(): void
    {
        $store = $this->shop();
        $this->withVite();
        $vite = app(Vite::class);
        $originalHotFile = $vite->hotFile();
        $hotFile = tempnam(sys_get_temp_dir(), 'storefront-vite-');
        file_put_contents($hotFile, 'http://vite.example.test:5173');
        $vite->useHotFile($hotFile);
        try {
            $response = $this->get('http://'.$store->slug.'.localhost/')->assertOk();
            $response->assertSee('<link rel="stylesheet" href="http://vite.example.test:5173/resources/css/app.css"', false);
            $html = $response->getContent();
            $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'resources/css/app.css'));
            $response->assertInertia(fn (Assert $page) => $page->where('renderedAt', (int) now()->format('Uv')));
        } finally {
            $vite->useHotFile($originalHotFile);
            unlink($hotFile);
        }
    }

    private function product(Store $store, string $category = 'Flash Sale', bool $stock = true): CatalogProduct
    {
        $product = CatalogProduct::factory()->create(['store_id' => $store->id, 'name' => 'Voucher Game', 'kind' => 'premium',
            'service' => 'Topup game', 'category' => $category, 'sale_price' => 15000, 'active' => true]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock']);
        if ($stock) {
            PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'status' => 'ready', 'cost' => 10000]);
        }

        return $product;
    }

    public function test_each_template_lists_category_products_by_price_before_pagination(): void
    {
        foreach (['nebula', 'aurora', 'atlas'] as $template) {
            $store = $this->shop();
            $store->update(['template' => $template]);
            $products = [];
            foreach ([60000, 10000, 50000, 20000, 40000, 30000] as $index => $price) {
                $product = $this->product($store, 'Kategori uji urutan');
                $product->update(['name' => 'Produk '.chr(65 + $index), 'sale_price' => $price, 'auto_update' => false]);
                $products[$price] = $product->id;
            }
            ksort($products);
            $ids = array_values($products);
            $base = 'http://'.$store->slug.'.localhost';
            foreach ([1, 2] as $number) {
                $expected = array_slice($ids, ($number - 1) * 5, 5);
                $assert = fn (Assert $page) => $page->where('products', fn ($rows) => collect($rows)->pluck('id')->all() === $expected)
                    ->where('pagination.total', 6)->where('pagination.current_page', $number);
                $this->get($base.'/kategori/'.$product->category_id.'?per_page=5&page='.$number)->assertOk()->assertInertia($assert);
                if ($template !== 'nebula') {
                    $this->get($base.'/?category='.urlencode('Kategori uji urutan').'&per_page=5&page='.$number)->assertOk()->assertInertia($assert);
                }
            }
        }
        Http::assertNothingSent();
    }

    private function order(Store $store, CatalogProduct $product, ?Customer $customer, array $overrides = []): CustomerOrder
    {
        return CustomerOrder::create([...['store_id' => $store->id, 'product_id' => $product->id, 'customer_id' => $customer?->id,
            'number' => 'TEST-'.Str::ulid(), 'idempotency_key' => Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'channel' => 'web', 'purpose' => 'product', 'status' => 'success', 'payment_status' => 'paid', 'gateway' => 'wallet',
            'phone' => '6281234567890', 'email' => 'private@example.test', 'destination' => 'PRIVATE-PLAYER-ID', 'snapshot' => ['name' => 'Voucher Game'],
            'amount' => 15000, 'total' => 17000, 'fee' => 2000, 'expires_at' => now()->addHour(), 'completed_at' => now()], ...$overrides]);
    }

    public function test_flash_sale_uses_available_catalog_prices_and_expiry_does_not_change_checkout_price(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $this->product($store, 'Flash Sale', false);
        $this->product($store, 'Other');
        $this->product($this->shop());
        $url = 'http://'.$store->slug.'.localhost/';
        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->has('gaming.flash_sale', 1)
            ->where('gaming.flash_sale.0.id', $product->id)->where('gaming.flash_sale.0.price', 15000)
            ->missing('gaming.flash_sale.0.cost')->missing('gaming.flash_sale.0.candidates')->has('gaming.trending', 0));
        $store->update(['branding' => ['gaming_flash_ends_at' => now()->subMinute()->toIso8601String()]]);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('gaming.flash_sale', 0));
        $this->assertSame(15000, $product->fresh()->sale_price);
        $store->update(['template' => 'aurora']);
        $this->get($url)->assertInertia(fn (Assert $page) => $page->has('gaming.flash_sale', 0)->has('gaming.trending', 0));
        Http::assertNothingSent();
    }

    public function test_rankings_use_only_successful_store_product_orders_and_never_expose_customer_identity(): void
    {
        $store = $this->shop();
        $product = $this->product($store, 'Mobile Legends');
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'PRIVATE-NAME', 'phone' => '6281234567890', 'email' => 'private@example.test']);
        $this->order($store, $product, $customer);
        $this->order($store, $product, $customer, ['amount' => 20000, 'completed_at' => now()->subWeek()]);
        foreach ([['status' => 'failed'], ['payment_status' => 'unpaid'], ['purpose' => 'topup'], ['refunded_at' => now()]] as $override) {
            $this->order($store, $product, $customer, [...$override, 'amount' => 999999]);
        }
        $this->order($store, $product, null, ['amount' => 50000]);
        $other = $this->shop();
        $this->order($other, $this->product($other, 'Foreign game'), null, ['amount' => 9999999]);
        $response = $this->get('http://'.$store->slug.'.localhost/');
        $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('gaming.trending', 1)
            ->where('gaming.trending.0.category', 'Mobile Legends')->missing('gaming.leaderboards'));
        $this->get('http://'.$store->slug.'.localhost/leaderboard?period=week')->assertInertia(fn (Assert $page) => $page->where('ranking.0.amount', 15000)->where('ranking.0.purchases', 1));
        $this->get('http://'.$store->slug.'.localhost/leaderboard')->assertInertia(fn (Assert $page) => $page->where('ranking.0.amount', 35000)->where('ranking.0.purchases', 2)->missing('ranking.0.email'));
        $response->assertDontSee('PRIVATE-NAME')->assertDontSee('private@example.test')->assertDontSee('6281234567890')->assertDontSee('PRIVATE-PLAYER-ID');
        $store->update(['status' => 'expired', 'expires_at' => now()->subDays(8)]);
        $this->get('http://'.$store->slug.'.localhost/')->assertInertia(fn (Assert $page) => $page->where('gaming', null)->has('products', 0));
    }

    public function test_owner_and_authorized_staff_can_save_nebula_appearance_without_touching_other_branding(): void
    {
        $store = $this->shop();
        $data = ['name' => $store->name, 'template' => 'nebula', 'appearance' => [
            'gaming_banner_title' => 'Saatnya mabar', 'gaming_banner_subtitle' => 'Voucher pilihan toko',
            'gaming_banner_image' => 'https://example.test/banner.webp', 'gaming_flash_category' => 'Promo Game',
            'gaming_flash_ends_at' => now()->addDay()->toIso8601String(),
        ]];
        $this->actingAs($store->owner)->patch('/owner/stores/'.$store->id.'/manage', $data)->assertSessionHasNoErrors();
        $this->assertSame('#abcdef', $store->fresh()->branding['accent']);
        $this->assertSame('Promo Game', $store->fresh()->branding['gaming_flash_category']);
        $this->get('/owner/stores/'.$store->id.'/manage')->assertInertia(fn (Assert $page) => $page->where('store.branding.gaming_banner_title', 'Saatnya mabar'));
        $invalid = $data;
        $invalid['appearance']['gaming_banner_image'] = 'javascript:alert(1)';
        $this->patch('/owner/stores/'.$store->id.'/manage', $invalid)->assertSessionHasErrors('appearance.gaming_banner_image');
        $invalid = $data;
        $invalid['appearance']['gaming_flash_ends_at'] = 'tomorrow';
        $this->patch('/owner/stores/'.$store->id.'/manage', $invalid)->assertSessionHasErrors('appearance.gaming_flash_ends_at');
        auth('web')->logout();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Designer', 'username' => 'designer', 'email' => 'designer@example.test', 'password' => 'fixture-password', 'permissions' => ['web.manage']]);
        $this->actingAs($staff, 'staff')->patch('/staff/'.$store->id.'/settings', $data)->assertSessionHasNoErrors();
        $this->get('/staff/'.$store->id.'/settings')->assertInertia(fn (Assert $page) => $page->where('store.branding.gaming_banner_title', 'Saatnya mabar'));
        $staff->update(['permissions' => ['dashboard.view']]);
        $this->patch('/staff/'.$store->id.'/settings', $data)->assertForbidden();
        Http::assertNothingSent();
    }
}
