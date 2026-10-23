<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\ProductPricing;
use App\Services\StoreCatalog;
use App\Services\SupplierCatalog;
use App\Support\CatalogAccess;
use Database\Seeders\CatalogDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'suppliers.cache_store' => 'array', 'cache.default' => 'array']);
        Http::preventStrayRequests();
        Cache::store('array')->flush();
    }

    private function shop(string $plan = 'galaxy'): Store
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.random_int(1000, 99999), 'phone_verified_at' => now(), 'email_verified_at' => now()]);

        return Store::create(['owner_id' => $owner->id, 'name' => 'Toko Katalog', 'slug' => 'catalog-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => $plan, 'status' => 'active', 'expires_at' => now()->addDays(30), 'template' => 'aurora']);
    }

    private function connection(Store $store, string $kind = 'digiflazz', string $key = 'account-a'): SupplierConnection
    {
        return SupplierConnection::create(['store_id' => $store->id, 'kind' => $kind, 'enabled' => true, 'verified_at' => now(),
            'credentials' => $kind === 'digiflazz' ? ['username' => 'buyer', 'api_key' => $key] : null]);
    }

    private function item(SupplierConnection $connection, int $cost = 10000, string $kind = 'ppob'): SupplierProduct
    {
        return SupplierProduct::create(['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'sku' => Str::random(12), 'name' => 'Produk Sumber', 'kind' => $kind, 'service' => 'Pulsa', 'category' => 'Telkomsel', 'cost' => $cost, 'available' => true, 'quantity' => null, 'observed_at' => now()]);
    }

    private function product(Store $store, ?SupplierProduct $item = null, array $override = []): CatalogProduct
    {
        $product = CatalogProduct::create([...['store_id' => $store->id, 'code' => 'P'.strtoupper(Str::random(10)), 'name' => 'Produk Toko', 'kind' => $item?->kind ?? 'premium', 'service' => 'Pulsa', 'category' => 'Telkomsel', 'active' => true, 'sale_price' => 15000], ...$override]);
        if ($item) {
            ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'supplier_product_id' => $item->id, 'source' => $item->connection->kind, 'source_key' => 'item:'.$item->id]);
        }

        return $product;
    }

    private function quote(Store $store, CatalogProduct $product): array
    {
        $service = app(StoreCatalog::class);

        return $service->present($service->query($store)->findOrFail($product->id), $store, true);
    }

    private function digiRow(int $price = 10000): array
    {
        return ['buyer_sku_code' => 'T10', 'product_name' => 'Telkomsel 10rb', 'category' => 'Pulsa', 'brand' => 'Telkomsel', 'price' => $price, 'buyer_product_status' => true, 'seller_product_status' => true, 'unlimited_stock' => true, 'stock' => 0];
    }

    public function test_markup_parser_priority_explicit_zero_and_rounding(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        PricingRule::create(['store_id' => $store->id, 'scope' => 'service', 'key' => 'pulsa', 'markup' => '5% + 5000', 'auto_update' => true]);
        PricingRule::create(['store_id' => $store->id, 'scope' => 'category', 'key' => 'telkomsel', 'markup' => '5%', 'auto_update' => null]);
        $pricing = app(ProductPricing::class);
        $this->assertSame(110000, $pricing->apply(100000, '5% + 5000'));
        $this->assertSame(10502, $pricing->apply(10001, '5%'));
        $this->assertSame('5%', $pricing->resolve($product)['markup']);
        $product->markup = '5000';
        $this->assertSame('5000', $pricing->resolve($product)['markup']);
        $product->markup = '0';
        $this->assertSame('0', $pricing->resolve($product)['markup']);
        $this->assertTrue($pricing->resolve($product)['auto_update']);
        $product->auto_update = false;
        $this->assertFalse($pricing->resolve($product)['auto_update']);
        $this->expectException(ValidationException::class);
        $pricing->parse('5% * shell_exec()');
    }

    public function test_pricing_rules_require_a_target_in_the_selected_level_and_store(): void
    {
        $store = $this->shop();
        $this->product($store, null, ['service' => 'Game', 'category' => 'Mobile Legends']);
        $other = $this->shop();
        $this->product($other, null, ['service' => 'Layanan Lain', 'category' => 'Kategori Lain']);
        $url = '/stores/'.$store->id.'/pricing';
        $this->actingAs($store->owner);
        foreach ([['category', 'Game'], ['service', 'Mobile Legends'], ['category', 'Kategori Lain'], ['service', 'Layanan Lain']] as [$scope, $key]) {
            $this->post($url, ['scope' => $scope, 'key' => $key, 'markup' => '10%', 'auto_update' => true])->assertSessionHasErrors('key');
        }
        $this->assertDatabaseCount('pricing_rules', 0);
    }

    public function test_service_rule_reprices_inherited_products_and_category_override_remains_independent(): void
    {
        $store = $this->shop();
        $item = $this->item($this->connection($store, 'hanshop'), 29494);
        $product = $this->product($store, $item, ['service' => 'Game', 'category' => 'Mobile Legends', 'sale_price' => 29494, 'markup' => null, 'auto_update' => null]);
        $url = '/stores/'.$store->id.'/pricing';
        $this->actingAs($store->owner)->post($url, ['scope' => 'service', 'key' => ' Game ', 'markup' => '10%', 'auto_update' => true])->assertSessionHasNoErrors();
        $this->assertSame(32444, $product->fresh()->sale_price);
        $quote = app(StoreCatalog::class)->present(app(StoreCatalog::class)->query($store, false)->findOrFail($product->id), $store, true);
        $this->assertSame(32444, $quote['price']);
        $this->assertSame(['markup' => '10%', 'auto_update' => true, 'level' => 'service'], $quote['rule']);

        $this->post($url, ['scope' => 'category', 'key' => 'Mobile Legends', 'markup' => '5000', 'auto_update' => null])->assertSessionHasNoErrors();
        $this->assertSame(34494, $product->fresh()->sale_price);
        $rule = PricingRule::where('store_id', $store->id)->where('scope', 'category')->firstOrFail();
        $this->patch($url.'/'.$rule->id, ['scope' => 'category', 'key' => 'Game', 'markup' => '5000', 'auto_update' => true])->assertSessionHasErrors('key');
        $this->assertSame('mobile legends', $rule->fresh()->key);
        $this->assertSame(34494, $product->fresh()->sale_price);
    }

    public function test_sources_are_shared_only_per_supplier_account_and_valid_snapshots_survive_failure(): void
    {
        $a = $this->shop();
        $b = $this->shop();
        $c = $this->shop();
        $one = $this->connection($a);
        $same = $this->connection($b);
        $other = $this->connection($c, 'digiflazz', 'account-b');
        Http::fake(['api.digiflazz.com/*' => Http::sequence()->push(['data' => [$this->digiRow()]])->push(['data' => [$this->digiRow()]])->push(['data' => ['rc' => '83', 'message' => 'Rate limited']])]);
        $service = app(SupplierCatalog::class);
        $service->sync($one);
        $service->sync($same);
        $service->sync($other);
        Http::assertSentCount(2);
        $this->assertDatabaseCount('supplier_products', 3);
        $this->assertStringNotContainsString('account-a', (string) DB::table('supplier_connections')->where('id', $one->id)->value('credentials'));
        $this->assertArrayNotHasKey('credentials', $one->toArray());
        $this->travel(121)->seconds();
        $service->sync($one);
        $service->sync($same);
        Http::assertSentCount(2);
        $this->travel(180)->seconds();
        try {
            $service->sync($one);
            $this->fail('Expected provider rejection');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('RC 83', $error->errors()['supplier'][0]);
            $this->assertStringContainsString('5 menit', $one->fresh()->last_error);
        }
        $this->assertTrue(SupplierProduct::where('connection_id', $one->id)->first()->available);
        $this->assertDatabaseCount('supplier_products', 3);
        $count = count(Http::recorded());
        try {
            $service->sync($one);
        } catch (ValidationException $error) {
        }
        $this->assertCount($count, Http::recorded());
    }

    public static function digiflazzRateLimitResponses(): array
    {
        return [
            'rc83 in successful HTTP response' => [200, ['data' => ['rc' => '83', 'message' => 'PRIVATE-RESPONSE']], 'RC 83'],
            'HTTP rate limit without JSON' => [429, [], 'HTTP 429'],
        ];
    }

    #[DataProvider('digiflazzRateLimitResponses')]
    public function test_digiflazz_rate_limit_waits_five_minutes_and_preserves_the_catalog_until_recovery(int $status, array $payload, string $reason): void
    {
        $connection = $this->connection($this->shop(), 'digiflazz', 'rate-'.$status);
        $item = $this->item($connection);
        $observed = $item->observed_at;
        Http::fake(['api.digiflazz.com/*' => Http::sequence()
            ->push($payload, $status)
            ->push(['data' => [$this->digiRow()]])]);
        $catalog = app(SupplierCatalog::class);
        foreach ([0, 121] as $seconds) {
            $this->travel($seconds)->seconds();
            try {
                $catalog->sync($connection);
                $this->fail('Expected rate limit');
            } catch (ValidationException $error) {
                $this->assertStringContainsString($reason, $error->errors()['supplier'][0]);
                $this->assertStringNotContainsString('PRIVATE-RESPONSE', $error->errors()['supplier'][0]);
            }
        }
        Http::assertSentCount(1);
        $this->assertTrue($item->fresh()->available);
        $this->assertEquals($observed, $item->fresh()->observed_at);
        $this->travel(180)->seconds();
        $this->assertSame(1, $catalog->sync($connection));
        Http::assertSentCount(2);
        $this->assertNull($connection->fresh()->last_error);
    }

    public static function digiflazzAuthenticationResponses(): array
    {
        return [
            ['41', 'Signature Digiflazz ditolak'],
            ['42', 'Username Digiflazz tidak sesuai'],
            ['82', 'belum terverifikasi'],
        ];
    }

    #[DataProvider('digiflazzAuthenticationResponses')]
    public function test_digiflazz_authentication_failures_expose_safe_specific_reasons_and_allow_recovery(string $code, string $message): void
    {
        $connection = $this->connection($this->shop(), 'digiflazz', 'auth-'.$code);
        Http::fake(['api.digiflazz.com/*' => Http::sequence()
            ->push(['data' => ['rc' => (string) $code, 'message' => 'SECRET-RESPONSE']], 400)
            ->push(['data' => [$this->digiRow()]])]);
        $catalog = app(SupplierCatalog::class);
        try {
            $catalog->sync($connection);
            $this->fail('Expected authentication error');
        } catch (ValidationException $error) {
            $this->assertStringContainsString($message, $error->errors()['supplier'][0]);
        }
        $this->assertStringContainsString('RC '.$code, $connection->fresh()->last_error);
        $this->assertStringNotContainsString('SECRET-RESPONSE', $connection->fresh()->last_error);
        $this->travel(16)->seconds();
        $this->assertSame(1, $catalog->sync($connection));
        $this->assertNull($connection->fresh()->last_error);
        Http::assertSentCount(2);
    }

    public function test_digiflazz_whitelist_failure_includes_the_rejected_ip_and_mode_without_leaking_response_text(): void
    {
        $connection = $this->connection($this->shop());
        $item = $this->item($connection);
        Http::fake(['api.digiflazz.com/*' => Http::response(['data' => ['rc' => '45', 'message' => 'IP 202.62.13.170 rejected SECRET-RESPONSE']], 400)]);
        try {
            app(SupplierCatalog::class)->sync($connection);
            $this->fail('Expected IP whitelist error');
        } catch (ValidationException $error) {
            $message = $error->errors()['supplier'][0];
            $this->assertStringContainsString('202.62.13.170', $message);
            $this->assertStringContainsString('Production (RC 45)', $message);
            $this->assertStringNotContainsString('SECRET-RESPONSE', $message);
            $this->assertSame($message, $connection->fresh()->last_error);
        }
        $this->assertTrue($item->fresh()->available);
        Http::assertSentCount(1);
    }

    public function test_digiflazz_invalid_product_data_never_replaces_the_last_valid_catalog(): void
    {
        $connection = $this->connection($this->shop());
        $item = $this->item($connection);
        Http::fake(['api.digiflazz.com/*' => Http::response(['data' => [['buyer_sku_code' => 'INVALID']]])]);
        try {
            app(SupplierCatalog::class)->sync($connection);
            $this->fail('Expected catalog validation error');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('Data produk Digiflazz tidak valid', $connection->fresh()->last_error);
        }
        $this->assertTrue($item->fresh()->available);
        $this->assertSame(10000, $item->fresh()->cost);
        $this->assertDatabaseCount('supplier_products', 1);
    }

    public function test_catalog_scheduler_keeps_the_specific_error_and_does_not_sync_file_based_otomax_connections(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store);
        $otomax = $this->connection($store, 'otomax');
        Http::fake(['api.digiflazz.com/*' => Http::response(['data' => ['rc' => '83', 'message' => 'Rate limited']])]);
        $this->artisan('digitaltoko:catalog-sync', ['--store' => $store->id])->assertExitCode(1);
        $this->assertStringContainsString('RC 83', $connection->fresh()->last_error);
        $this->assertNull($otomax->fresh()->last_error);
        Http::assertSentCount(1);
    }

    public function test_source_features_expiry_and_staff_permissions_are_enforced_server_side(): void
    {
        $space = $this->shop('space');
        $orbit = $this->shop('orbit');
        $this->assertTrue(CatalogAccess::supports($space, 'digiflazz'));
        $this->assertTrue(CatalogAccess::supports($orbit, 'stock'));
        $this->actingAs($orbit->owner)->post('/stores/'.$space->id.'/products', ['code' => 'HACK'])->assertForbidden();
        $staff = StoreStaff::create(['store_id' => $orbit->id, 'name' => 'Stock staff', 'username' => 'operator', 'email' => 'operator@example.test', 'password' => 'test-password-123', 'permissions' => ['stock.manage']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->post('/stores/'.$orbit->id.'/stock', ['product_id' => (string) Str::uuid(), 'cost' => 10, 'entries' => ['private-secret']])->assertNotFound();
        $this->post('/stores/'.$orbit->id.'/sources', ['kind' => 'hanshop', 'enabled' => true])->assertForbidden();
        Http::assertNothingSent();
        $orbit->update(['status' => 'expired', 'expires_at' => now()->subDays(8)]);
        $this->actingAs($orbit->owner, 'web')->post('/stores/'.$orbit->id.'/products', ['code' => 'NEW'])->assertForbidden();
    }

    public function test_import_is_idempotent_and_product_parsing_cannot_cross_stores(): void
    {
        $store = $this->shop();
        $item = $this->item($this->connection($store));
        $foreign = $this->item($this->connection($this->shop()));
        $base = '/stores/'.$store->id.'/products';
        $this->actingAs($store->owner)->post($base.'/import', ['ids' => [$item->id]])->assertSessionHasNoErrors();
        $this->post($base.'/import', ['ids' => [$item->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('catalog_products', 1);
        $this->assertDatabaseCount('product_mappings', 1);
        $product = CatalogProduct::firstOrFail();
        $data = ['code' => $product->code, 'name' => 'Nama baru', 'kind' => 'ppob', 'category' => 'Telkomsel', 'service' => 'Pulsa', 'active' => true, 'mapping_ids' => [$foreign->id], 'use_stock' => false, 'sale_price' => 20000];
        $this->patch($base.'/'.$product->id, $data)->assertUnprocessable();
        $this->assertSame('Produk Sumber', $product->fresh()->name);
        $data['mapping_ids'] = [$item->id];
        $data['markup'] = '5% + 5000';
        $data['auto_update'] = true;
        $this->patch($base.'/'.$product->id, $data)->assertSessionHasNoErrors();
        $this->assertSame(15500, $this->quote($store, $product)['price']);
        $this->assertSame('5% + 5000', $product->fresh()->markup);
        ProductMapping::where('product_id', $product->id)->delete();
        $this->post($base.'/import', ['ids' => [$item->id]])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('catalog_products', 2);
        $this->assertSame(2, CatalogProduct::where('store_id', $store->id)->distinct()->count('code'));

    }

    public function test_cheapest_available_parsing_rules_cost_increase_and_downgrade_change_catalog_availability(): void
    {
        $store = $this->shop();
        $digi = $this->item($this->connection($store), 10000);
        $hanshop = $this->item($this->connection($store, 'hanshop'), 9000);
        $product = $this->product($store, $digi, ['sale_price' => 9500, 'auto_update' => false]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'supplier_product_id' => $hanshop->id, 'source' => 'hanshop', 'source_key' => 'item:'.$hanshop->id]);
        $quote = $this->quote($store, $product);
        $this->assertSame(9000, $quote['cost']);
        $this->assertTrue($quote['available']);
        $hanshop->update(['available' => false]);
        $quote = $this->quote($store, $product);
        $this->assertSame(10000, $quote['cost']);
        $this->assertFalse($quote['available']);
        $this->assertTrue($quote['price_blocked']);
        $product->update(['auto_update' => true, 'markup' => '1000']);
        $this->assertSame(11000, $this->quote($store, $product)['price']);
        $store->update(['plan' => 'space']);
        $this->assertTrue($this->quote($store, $product)['available']);
        $hanshop->update(['available' => true]);
        $this->assertTrue($this->quote($store, $product)['available']);
        $this->travel(601)->seconds();
        $this->assertFalse($this->quote($store, $product)['available']);
    }

    public function test_opening_catalog_refreshes_expired_hanshop_stock_and_shares_cache_per_owner(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('h', 40)]);
        $store = $this->shop();
        $other = $this->shop();
        $other->update(['owner_id' => $store->owner_id]);
        $products = [];
        foreach ([$store, $other] as $shop) {
            $connection = $this->connection($shop, 'hanshop');
            $connection->update(['synced_at' => now()->subMinutes(11), 'catalog_modes' => ['prepaid']]);
            $item = $this->item($connection, 10000, 'premium');
            $item->update(['sku' => 'NET1', 'quantity' => 2, 'observed_at' => now()->subMinutes(11)]);
            $products[] = $this->product($shop, $item);
        }
        $quantity = 2;
        Http::fake(function () use ($store, &$quantity) {
            return Http::response(['status' => 'SUCCESS', 'data' => [
                'id_user' => $store->owner->hanshop_user_id, 'items' => [[
                    'code' => 'NET1', 'name' => 'Netflix', 'kind' => 'premium', 'price' => 10000,
                    'service_name' => 'Aplikasi', 'category_name' => 'Netflix', 'available' => $quantity > 0, 'quantity' => $quantity,
                ]], 'next_cursor' => null,
            ]]);
        });
        foreach ([$store, $other] as $shop) {
            $this->actingAs($store->owner)->get('/stores/'.$shop->id.'/products')
                ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('products.0.available', true)->where('products.0.quantity', 2)->where('products.0.supplier_stale', false));
        }
        Http::assertSentCount(1);
        $this->assertSame(2, $this->quote($store, $products[0])['quantity']);
        Http::assertSentCount(1);

        $this->travel(121)->seconds();
        $quantity = 0;
        $quote = $this->quote($store, $products[0]);
        $this->assertFalse($quote['available']);
        $this->assertSame(0, $quote['quantity']);
        $this->assertFalse($quote['supplier_stale']);
    }

    public function test_failed_stock_refresh_keeps_snapshot_and_does_not_claim_stale_stock_is_zero(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('h', 40)]);
        $store = $this->shop();
        $connection = $this->connection($store, 'hanshop');
        $connection->update(['synced_at' => now()->subMinutes(3), 'catalog_modes' => ['prepaid']]);
        $item = $this->item($connection, 10000, 'premium');
        $item->update(['quantity' => 2, 'observed_at' => now()->subMinutes(3)]);
        $product = $this->product($store, $item);
        Http::fake(['hanshop.test/*' => Http::response(['status' => 'ERROR'], 503)]);

        $quote = $this->quote($store, $product);
        $this->assertTrue($quote['available']);
        $this->assertSame(2, $quote['quantity']);
        $this->travel(421)->seconds();
        $this->actingAs($store->owner)->get('/stores/'.$store->id.'/products')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.available', false)
                ->where('products.0.quantity', null)->where('products.0.supplier_stale', true));
        $this->quote($store, $product);
        Http::assertSentCount(2);
        $this->assertSame(2, $item->fresh()->quantity);
        $this->assertTrue($item->fresh()->available);
    }

    public function test_stock_is_encrypted_scoped_counted_and_not_exposed_without_separate_credential_permission(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $base = '/stores/'.$store->id.'/stock';
        $this->actingAs($store->owner)->post($base, ['product_id' => $product->id, 'cost' => 12000, 'note' => 'Batch A', 'entries' => ["  email: private-stock@example.test\r\npassword: secret-123  ", 'second-account']])->assertSessionHasNoErrors();
        $stock = PremiumStock::firstOrFail();
        $this->assertDatabaseCount('premium_stocks', 2);
        $this->assertSame("  email: private-stock@example.test\r\npassword: secret-123  ", $stock->payload);
        $this->assertStringNotContainsString('secret-123', (string) DB::table('premium_stocks')->where('id', $stock->id)->value('payload'));
        $this->assertSame(2, $this->quote($store, $product)['quantity']);
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Stock manager', 'username' => 'stockmanager', 'email' => 'stockmanager@example.test', 'password' => 'test-password-123', 'permissions' => ['stock.view', 'stock.manage']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->get($base)->assertOk()->assertDontSee('secret-123');
        $inertiaHeaders = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion()];
        $this->get($base, $inertiaHeaders)->assertOk()->assertHeader('X-Inertia', 'true')
            ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('component', 'Owner/Stock')
            ->assertJsonPath('encryptHistory', true)->assertJsonCount(2, 'props.stocks')->assertDontSee('secret-123');
        $this->get($base.'?status=ready&per_page=5', [...$inertiaHeaders, 'X-Inertia-Partial-Component' => 'Owner/Stock', 'X-Inertia-Partial-Data' => 'stocks,pagination,filters'])
            ->assertOk()->assertJsonPath('props.pagination.total', 2)->assertJsonPath('props.filters.status', 'ready')->assertDontSee('secret-123');
        $this->postJson($base.'/'.$stock->id.'/reveal')->assertForbidden();
        $staff->update(['permissions' => ['stock.view', 'stock.manage', 'stock.credentials']]);
        $this->postJson($base.'/'.$stock->id.'/reveal')->assertOk()->assertJsonPath('data.payload', $stock->payload)->assertHeader('Cache-Control', 'no-store, private');
        $stock->update(['status' => 'sent', 'order_reference' => 'ORDER-TEST']);
        $this->delete($base.'/'.$stock->id)->assertUnprocessable();
        $this->patch($base.'/'.$stock->id, ['cost' => 1000])->assertUnprocessable();
        $this->assertSame(1, $this->quote($store, $product)['quantity']);
    }

    public function test_bot_catalog_is_scoped_paginated_and_does_not_return_cost_or_stock_secrets(): void
    {
        $store = $this->shop();
        $token = str_repeat('w', 40);
        $store->update(['worker_token_hash' => hash('sha256', $token)]);
        $product = $this->product($store, $this->item($this->connection($store)));
        for ($i = 0; $i < 12; $i++) {
            $this->product($store, null, ['name' => 'Aplikasi '.$i, 'active' => true]);
        }
        $this->product($this->shop(), null, ['name' => 'Produk Rahasia Lain']);
        $this->getJson('/api/internal/bot/products')->assertUnauthorized();
        $response = $this->withToken($token)->getJson('/api/internal/bot/products?page=2')->assertOk()->assertJsonCount(3, 'data.items')->assertJsonPath('data.pagination.total', 13)->assertJsonMissingPath('data.items.0.cost')->assertDontSee('Produk Rahasia Lain');
        $this->getJson('/api/internal/bot/products?q='.$product->code)->assertJsonPath('data.items.0.code', $product->code)->assertJsonPath('data.items.0.price', 15000);
        $store->update(['category' => 'web']);
        $this->getJson('/api/internal/bot/products')->assertForbidden();
    }

    public function test_hanshop_uses_linked_owner_and_staff_never_receives_global_balance(): void
    {
        $store = $this->shop('orbit');
        $connection = $this->connection($store, 'hanshop');
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('t', 40)]);
        Http::fake(['hanshop.test/internal/digitaltoko/supplier/balance' => Http::response(['status' => 'SUCCESS', 'data' => ['id_user' => $store->owner->hanshop_user_id, 'balance' => 50000]])]);
        $this->actingAs($store->owner)->getJson('/stores/'.$store->id.'/balances')->assertOk()->assertJsonPath('data.hanshop.amount', 50000);
        Http::assertSent(fn ($request) => $request['id_user'] === $store->owner->hanshop_user_id && $request['store_id'] === $store->id && $request->hasHeader('Authorization', 'Bearer '.str_repeat('t', 40)));
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Balance staff', 'username' => 'balancestaff', 'email' => 'balance@example.test', 'password' => 'test-password-123', 'permissions' => ['suppliers.balance']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->getJson('/stores/'.$store->id.'/balances')->assertOk()->assertJsonPath('data.hanshop.status', 'restricted')->assertJsonMissingPath('data.hanshop.amount');
        Http::assertSentCount(1);
    }

    public function test_connecting_supplier_verifies_its_balance_and_never_exposes_credentials_or_flashes_them_on_validation(): void
    {
        $store = $this->shop();
        Http::fake(['api.digiflazz.com/v1/cek-saldo' => Http::response(['data' => ['deposit' => 50000]])]);
        $base = '/stores/'.$store->id.'/sources';
        $this->actingAs($store->owner)->post($base, ['kind' => 'digiflazz', 'username' => 'buyer-test', 'api_key' => 'private-api-key', 'enabled' => true])->assertSessionHasNoErrors();
        $connection = SupplierConnection::firstOrFail();
        $this->assertSame('private-api-key', $connection->credentials['api_key']);
        Http::assertSent(fn ($request) => $request['sign'] === md5('buyer-testprivate-api-keydepo') && $request['cmd'] === 'deposit');
        $this->get($base)->assertOk()->assertDontSee('private-api-key')->assertDontSee('buyer-test');
        $this->post($base, ['kind' => 'digiflazz', 'username' => '', 'api_key' => '', 'enabled' => true])->assertSessionHasNoErrors();
        $this->assertSame('private-api-key', $connection->fresh()->credentials['api_key']);
        Http::assertSentCount(1);
        $this->post($base, ['kind' => 'invalid', 'api_key' => 'not-to-flash', 'enabled' => true])->assertSessionHasErrors('kind')->assertSessionMissing('_old_input.api_key');
        $this->delete($base.'/'.$connection->id)->assertSessionHasNoErrors();
        $this->assertNull($connection->fresh()->credentials);
        $this->assertFalse($connection->fresh()->enabled);
    }

    public function test_postpaid_and_prepaid_snapshots_with_the_same_sku_remain_separate(): void
    {
        $store = $this->shop();
        $connection = $this->connection($store);
        Http::fake(['api.digiflazz.com/*' => fn ($request) => Http::response(['data' => [$request['cmd'] === 'pasca'
            ? ['buyer_sku_code' => 'T10', 'product_name' => 'Tagihan PLN', 'category' => 'Pascabayar', 'brand' => 'PLN', 'admin' => 2750, 'commission' => 1800, 'buyer_product_status' => true, 'seller_product_status' => true]
            : $this->digiRow()]])]);
        $catalog = app(SupplierCatalog::class);
        $catalog->sync($connection);
        $catalog->sync($connection, 'pasca');
        $this->assertDatabaseCount('supplier_products', 2);
        $this->assertEqualsCanonicalizing(['pasca', 'prepaid'], $connection->fresh()->catalog_modes);
        $item = SupplierProduct::where('mode', 'pasca')->firstOrFail();
        $product = $this->product($store, $item, ['sale_price' => 3000]);
        $this->assertSame('Biaya admin', $this->quote($store, $product)['price_label']);
        $this->assertSame(2750, $this->quote($store, $product)['cost']);
    }

    public function test_web_and_bot_share_prices_and_public_queries_do_not_return_private_stock_or_supplier_fields(): void
    {
        $store = $this->shop();
        $token = str_repeat('z', 40);
        $store->update(['worker_token_hash' => hash('sha256', $token)]);
        $product = $this->product($store, null, ['name' => 'Canva Toko', 'category' => 'Canva', 'service' => 'Premium', 'sale_price' => 22000]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'stock', 'source_key' => 'stock']);
        PremiumStock::factory()->create(['store_id' => $store->id, 'product_id' => $product->id, 'payload' => 'PRIVATE-STOCK-DATA', 'cost' => 10000]);
        $this->product($store, null, ['name' => 'Nonaktif', 'active' => false]);
        $this->product($this->shop(), null, ['name' => 'Produk Toko Lain']);
        $web = $this->get('http://'.$store->slug.'.localhost/?q=Canva')->assertOk()->assertDontSee('PRIVATE-STOCK-DATA')->assertDontSee('Produk Toko Lain');
        $web->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.price', 22000)->where('products.0.quantity', 1)->where('products.0.available', true)->missing('products.0.cost')->missing('products.0.candidates'));
        $this->withToken($token)->getJson('http://localhost/api/internal/bot/products?q=Canva')->assertOk()->assertJsonPath('data.items.0.price', 22000)->assertJsonPath('data.items.0.quantity', 1);
        $store->update(['status' => 'expired', 'expires_at' => now()->subDays(8)]);
        $this->get('http://'.$store->slug.'.localhost/')->assertInertia(fn (AssertableInertia $page) => $page->has('products', 0)->where('available', false));
    }

    public function test_pricing_only_staff_can_edit_prices_but_cannot_change_parsing_or_access_other_stores(): void
    {
        $store = $this->shop();
        $product = $this->product($store);
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Pricing', 'username' => 'pricing', 'email' => 'pricing@example.test', 'password' => 'test-password-123', 'permissions' => ['pricing.manage']]);
        $url = '/stores/'.$store->id.'/products/'.$product->id;
        $this->actingAs($staff, 'staff')->get('/stores/'.$store->id.'/products')->assertOk();
        $this->patch($url.'/price', ['sale_price' => 25000, 'markup' => '0', 'auto_update' => false])->assertSessionHasNoErrors();
        $this->assertSame(25000, $product->fresh()->sale_price);
        $this->patch($url, ['name' => 'Tidak diizinkan'])->assertForbidden();
        $foreign = $this->product($this->shop());
        $this->patch('/stores/'.$store->id.'/products/'.$foreign->id.'/price', ['sale_price' => 1, 'markup' => '0', 'auto_update' => false])->assertNotFound();
    }

    public function test_dummy_catalog_is_repeatable_and_never_connects_or_buys_from_real_suppliers(): void
    {
        $this->seed(CatalogDemoSeeder::class);
        $products = CatalogProduct::count();
        $stocks = PremiumStock::count();
        $this->seed(CatalogDemoSeeder::class);
        $this->assertSame($products, CatalogProduct::count());
        $this->assertSame($stocks, PremiumStock::count());
        $this->assertGreaterThan(20, $products);
        $this->assertGreaterThan(0, $stocks);
        $this->assertDatabaseCount('supplier_connections', 0);
        Http::assertNothingSent();
    }

    public function test_hanshop_connection_failures_keep_the_real_reason_and_recover_after_a_short_backoff(): void
    {
        $store = $this->shop();
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('h', 40)]);
        $key = 'supplier:v1:hanshop:'.hash_hmac('sha256', 'https://hanshop.test:'.$store->owner->hanshop_user_id, config('app.key')).':balance';
        Cache::store('array')->put($key.':cooldown', true, 120);
        $attempts = 0;
        Http::fake(function () use (&$attempts, $store) {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('Private transport details with TOKEN-DO-NOT-RETURN');
            }

            return Http::response(['status' => 'SUCCESS', 'data' => ['id_user' => $store->owner->hanshop_user_id, 'balance' => 50000]]);
        });
        $url = '/stores/'.$store->id.'/sources';
        $this->actingAs($store->owner)->post($url, ['kind' => 'hanshop', 'enabled' => true])->assertSessionHasErrors('supplier');
        $this->assertStringContainsString('API Hanshop tidak dapat dihubungi', session('errors')->first('supplier'));
        $this->assertStringNotContainsString('TOKEN-DO-NOT-RETURN', session('errors')->first('supplier'));
        $this->post($url, ['kind' => 'hanshop', 'enabled' => true])->assertSessionHasErrors('supplier');
        $this->assertStringContainsString('API Hanshop tidak dapat dihubungi', session('errors')->first('supplier'));
        $this->assertStringNotContainsString('membatasi', session('errors')->first('supplier'));
        $this->assertSame(1, $attempts);
        $this->assertDatabaseCount('supplier_connections', 0);
        $this->travel(16)->seconds();
        $this->post($url, ['kind' => 'hanshop', 'enabled' => true])->assertSessionHasNoErrors();
        $this->assertSame(2, $attempts);
        $this->assertDatabaseHas('supplier_connections', ['store_id' => $store->id, 'kind' => 'hanshop', 'enabled' => true]);
    }

    public function test_hanshop_http_errors_distinguish_token_endpoint_and_real_rate_limits_without_exposing_response_bodies(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('h', 40)]);
        $attempts = 0;
        $currentStatus = 0;
        $accountId = '';
        Http::fake(function () use (&$attempts, &$currentStatus, &$accountId) {
            $attempts++;

            return $attempts === 1
                ? Http::response(['status' => 'ERROR', 'message' => 'PRIVATE-SERVER-ERROR'], $currentStatus)
                : Http::response(['status' => 'SUCCESS', 'data' => ['id_user' => $accountId, 'balance' => 50000]]);
        });
        foreach ([401 => 'Token integrasi Hanshop ditolak', 404 => 'Endpoint pemasok Hanshop belum tersedia', 503 => 'Layanan API Hanshop sedang bermasalah', 429 => 'Hanshop sedang membatasi permintaan'] as $status => $message) {
            $store = $this->shop();
            $attempts = 0;
            $currentStatus = $status;
            $accountId = $store->owner->hanshop_user_id;
            $url = '/stores/'.$store->id.'/sources';
            $this->actingAs($store->owner)->post($url, ['kind' => 'hanshop', 'enabled' => true])->assertSessionHasErrors('supplier');
            $this->assertStringContainsString($message, session('errors')->first('supplier'));
            $this->assertStringNotContainsString('PRIVATE-SERVER-ERROR', session('errors')->first('supplier'));
            $this->post($url, ['kind' => 'hanshop', 'enabled' => true])->assertSessionHasErrors('supplier');
            $this->assertStringContainsString($message, session('errors')->first('supplier'));
            $this->assertSame(1, $attempts);
            if ($status === 429) {
                $this->travel(16)->seconds();
                $this->post($url, ['kind' => 'hanshop', 'enabled' => true])->assertSessionHasErrors('supplier');
                $this->assertSame(1, $attempts);
                $this->travel(105)->seconds();
            } else {
                $this->travel(16)->seconds();
            }
            $this->post($url, ['kind' => 'hanshop', 'enabled' => true])->assertSessionHasNoErrors();
            $this->assertSame(2, $attempts);
        }
    }
}
