<?php

namespace Tests\Feature;

use App\Jobs\SyncOtomaxCatalog;
use App\Models\CatalogProduct;
use App\Models\OtomaxImport;
use App\Models\OtomaxProfile;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\OtomaxCatalogSync;
use App\Services\OtomaxImports;
use App\Services\OtomaxParser;
use App\Services\OtomaxWebCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OtomaxUrlCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'suppliers.cache_store' => 'array']);
        Cache::flush();
        Http::preventStrayRequests();
        Queue::fake([SyncOtomaxCatalog::class]);
        Storage::fake('local');
    }

    private function connection(): SupplierConnection
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved']);
        $store = Store::create(['owner_id' => $owner->id, 'slug' => 'url-'.Str::lower(Str::random(8)), 'name' => 'Toko URL', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addMonth()]);
        $profile = OtomaxProfile::create(['store_id' => $store->id, 'name' => 'Jawaban', 'type' => 'response', 'config' => OtomaxParser::defaults()]);

        return SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => Str::uuid(), 'name' => 'Supplier URL', 'enabled' => true, 'settings' => ['base_url' => 'https://8.8.8.8', 'catalog_hours' => 24], 'credentials' => ['member_id' => 'DEMO', 'pin' => '1111', 'password' => 'fake', 'callback_token' => str_repeat('f', 48)], 'response_profile_id' => $profile->id]);
    }

    private function input(string $token = 'fake-a'): array
    {
        return ['url' => 'https://8.8.8.8/harga.js.php?id='.$token, 'automatic' => true, 'interval' => 15, 'profile' => OtomaxWebCatalog::defaults()];
    }

    private function html(array $rows = [['001', 'Pulsa 10rb', '10.000', 'Open']], bool $reordered = false): string
    {
        $html = '<table><tr><td colspan="4">PULSA TELKOMSEL</td></tr><tr>'.($reordered ? '<th>Status</th><th>Harga</th><th>Keterangan</th><th>Kode</th>' : '<th>Kode</th><th>Keterangan</th><th>Harga</th><th>Status</th>').'</tr>';
        foreach ($rows as $row) {
            if ($reordered) {
                $row = array_reverse($row);
            }
            $html .= '<tr><td>'.implode('</td><td>', array_map(fn ($value) => htmlspecialchars($value), $row)).'</td></tr>';
        }

        return $html.'</table>';
    }

    private function base(SupplierConnection $connection): string
    {
        return '/stores/'.$connection->store_id.'/otomax/connections/'.$connection->id.'/catalog';
    }

    private function preview(SupplierConnection $connection, ?array $input = null): OtomaxImport
    {
        $response = $this->actingAs($connection->store->owner)->postJson($this->base($connection).'/preview', $input ?? $this->input())->assertOk();

        return OtomaxImport::findOrFail($response->json('id'));
    }

    private function activate(SupplierConnection $connection, OtomaxImport $preview, bool $accept = false): void
    {
        $this->actingAs($connection->store->owner)->post($this->base($connection), ['preview_id' => $preview->id, 'accept_missing' => $accept])->assertSessionHasNoErrors();
        (new SyncOtomaxCatalog($preview->id))->handle(app(OtomaxCatalogSync::class));
        $this->assertSame('completed', $preview->fresh()->status, $preview->fresh()->error ?? '');
    }

    public function test_webreport_columns_can_move_and_preserve_sku_zeroes_and_unknown_stock(): void
    {
        $parser = app(OtomaxWebCatalog::class);
        foreach ([false, true] as $reordered) {
            $rows = $parser->parse($this->html([['001', 'Pulsa', '10.000', 'Open'], ['002', 'Data', '12.500', 'Gangguan']], $reordered), OtomaxWebCatalog::defaults());
            $this->assertSame('001', $rows[0]['sku']);
            $this->assertSame(10000, $rows[0]['cost']);
            $this->assertTrue($rows[0]['available']);
            $this->assertNull($rows[0]['quantity']);
            $this->assertFalse($rows[1]['available']);
            $zero = $parser->parse($this->html([['000', 'Cek ID', '0', 'Open']]), OtomaxWebCatalog::defaults())[0];
            $this->assertTrue($zero['available']);
            $this->assertSame('postpaid', $zero['kind']);
            $this->assertSame(0, $zero['billing']['adjustment']);
            $this->assertStringContainsString('pasangan', $zero['availability_reason']);
        }
    }

    public function test_preview_is_private_and_apply_preserves_catalog_rules_then_missing_products_become_unavailable(): void
    {
        $connection = $this->connection();
        $html = $this->html([['001', 'Pulsa', '10.000', 'Open'], ['002', 'Paket data', '20.000', 'Open']]);
        Http::fake(function () use (&$html) {
            return Http::response($html);
        });
        $preview = $this->preview($connection);
        $this->assertDatabaseCount('supplier_products', 0);
        $this->assertStringNotContainsString('fake-a', DB::table('otomax_imports')->where('id', $preview->id)->value('payload'));
        $this->activate($connection, $preview);
        $one = SupplierProduct::where('connection_id', $connection->id)->where('sku', '001')->sole();
        $gone = SupplierProduct::where('connection_id', $connection->id)->where('sku', '002')->sole();
        $product = CatalogProduct::create(['store_id' => $connection->store_id, 'code' => 'OWN', 'name' => 'Nama toko', 'kind' => 'ppob', 'service' => 'Game', 'category' => 'Kategori sendiri', 'active' => true, 'sale_price' => 15000, 'markup' => '10%', 'auto_update' => true]);
        ProductMapping::create(['store_id' => $connection->store_id, 'product_id' => $product->id, 'supplier_product_id' => $one->id, 'source' => 'otomax', 'source_key' => 'item:'.$one->id]);
        $this->travel(6)->minutes();
        $html = $this->html([['001', 'Nama supplier baru', '12.000', 'Open']]);
        $this->activate($connection->fresh(), $this->preview($connection->fresh()));
        $this->assertSame($one->id, SupplierProduct::where('sku', '001')->sole()->id);
        $this->assertFalse($gone->fresh()->available);
        $this->assertSame('Tidak ditemukan pada daftar harga supplier.', $gone->fresh()->availability_reason);
        $this->assertSame(13200, $product->fresh()->sale_price);
        $this->assertSame('Nama toko', $product->fresh()->name);
        $this->assertSame('Kategori sendiri', $product->fresh()->category);
        $this->assertStringNotContainsString('fake-a', DB::table('supplier_connections')->where('id', $connection->id)->value('catalog_config'));
        $this->get('/stores/'.$connection->store_id.'/otomax')->assertInertia(fn (AssertableInertia $page) => $page->missing('items.0.catalog_config')->missing('items.0.credentials'));
    }

    public function test_invalid_html_duplicate_sku_zero_price_and_unknown_status_never_pass_as_a_full_snapshot(): void
    {
        $bad = ['', '<html>Login</html>', substr($this->html(), 0, -8), $this->html([['001', 'One', '100', 'NEW']]), $this->html([['001', 'One', '100', 'Open'], ['001', 'Two', '100', 'Open']])];
        foreach ($bad as $html) {
            try {
                app(OtomaxWebCatalog::class)->parse($html, OtomaxWebCatalog::defaults());
                $this->fail('Invalid feed accepted');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors()['catalog']);
            }
        }
    }

    public function test_private_urls_and_redirects_are_rejected_without_leaking_access_tokens(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $connection = $this->connection();
        foreach (['http://8.8.8.8/list', 'https://127.0.0.1/list', 'https://192.168.0.2/list', 'https://[::1]/list', 'https://[::ffff:127.0.0.1]/list', 'https://100.64.0.1/list', 'https://224.0.0.1/list', 'https://user:pass@8.8.8.8/list'] as $url) {
            $this->actingAs($connection->store->owner)->postJson($this->base($connection).'/preview', [...$this->input(), 'url' => $url])->assertUnprocessable();
        }
        Http::assertNothingSent();
        Http::fake(fn () => Http::response('', 302, ['Location' => 'http://127.0.0.1/private']));
        $this->postJson($this->base($connection).'/preview', $this->input())->assertUnprocessable()->assertDontSee('fake-a');
        Http::assertSentCount(1);
    }

    public function test_cross_store_preview_reuse_staff_permissions_and_sandbox_are_enforced(): void
    {
        $connection = $this->connection();
        $other = $this->connection();
        Http::fake(fn () => Http::response($this->html()));
        $preview = $this->preview($connection);
        $this->actingAs($other->store->owner)->post($this->base($other), ['preview_id' => $preview->id, 'accept_missing' => false])->assertNotFound();
        $staff = StoreStaff::create(['store_id' => $connection->store_id, 'name' => 'Viewer', 'username' => 'url-view', 'email' => 'viewer@example.test', 'password' => 'fake-only-password', 'permissions' => ['products.view']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->postJson($this->base($connection).'/preview', $this->input())->assertForbidden();
        $this->post($this->base($connection).'/sync')->assertForbidden();
        auth('staff')->logout();
        $connection->store->update(['sandbox' => true]);
        $this->actingAs($connection->store->owner, 'web')->postJson($this->base($connection).'/preview', $this->input())->assertUnprocessable();
        Http::assertSentCount(1);
    }

    public function test_scheduler_deduplicates_jobs_and_failure_preserves_the_previous_snapshot(): void
    {
        $connection = $this->connection();
        $failure = false;
        Http::fake(function () use (&$failure) {
            return Http::response($failure ? 'Unavailable' : $this->html(), $failure ? 503 : 200);
        });
        $this->activate($connection, $this->preview($connection));
        $item = SupplierProduct::where('connection_id', $connection->id)->sole();
        $observed = $item->observed_at;
        Queue::fake([SyncOtomaxCatalog::class]);
        $this->travel(20)->minutes();
        $failure = true;
        $this->artisan('digitaltoko:otomax-catalogs')->assertSuccessful();
        $this->artisan('digitaltoko:otomax-catalogs')->assertSuccessful();
        Queue::assertPushed(SyncOtomaxCatalog::class, 1);
        $run = OtomaxImport::where('status', 'queued')->sole();
        (new SyncOtomaxCatalog($run->id))->handle(app(OtomaxCatalogSync::class));
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertTrue($item->fresh()->available);
        $this->assertEquals($observed, $item->fresh()->observed_at);
        $this->assertStringContainsString('HTTP 503', $connection->fresh()->catalog_error);
    }

    public function test_newer_callback_price_is_not_overwritten_and_old_jobs_stop_after_pause(): void
    {
        $connection = $this->connection();
        Http::fake(fn () => Http::response($this->html()));
        $this->activate($connection, $this->preview($connection));
        $this->travel(6)->minutes();
        $preview = $this->preview($connection->fresh());
        $this->travel(1)->seconds();
        $item = SupplierProduct::where('connection_id', $connection->id)->sole();
        $item->update(['cost' => 12500, 'price_observed_at' => now()]);
        $this->activate($connection->fresh(), $preview);
        $this->assertSame(12500, $item->fresh()->cost);
        $this->travel(6)->minutes();
        app(OtomaxCatalogSync::class)->enqueue($connection->fresh());
        $queued = OtomaxImport::where('status', 'queued')->sole();
        $this->actingAs($connection->store->owner)->post($this->base($connection).'/pause')->assertSessionHasNoErrors();
        (new SyncOtomaxCatalog($queued->id))->handle(app(OtomaxCatalogSync::class));
        $this->assertSame('failed', $queued->fresh()->status);
        $this->assertSame(12500, $item->fresh()->cost);
        $this->assertFalse($connection->fresh()->catalog_config['automatic']);
    }

    public function test_large_drop_needs_confirmation_and_missing_skus_can_return_without_changing_other_connections(): void
    {
        $connection = $this->connection();
        $other = $this->connection();
        $otherItem = SupplierProduct::create(['store_id' => $other->store_id, 'connection_id' => $other->id, 'sku' => '000', 'name' => 'Lain', 'service' => 'Pulsa', 'category' => 'Lain', 'cost' => 100, 'available' => true, 'observed_at' => now()]);
        $rows = [];
        for ($i = 0; $i < 24; $i++) {
            $rows[] = [str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'Produk '.$i, '1.000', 'Open'];
        }
        $html = $this->html($rows);
        Http::fake(function () use (&$html) {
            return Http::response($html);
        });
        $this->activate($connection, $this->preview($connection));
        $this->travel(6)->minutes();
        $html = $this->html([$rows[0]]);
        $preview = $this->preview($connection->fresh());
        $this->assertTrue($preview->summary['needs_confirmation']);
        $this->actingAs($connection->store->owner)->postJson($this->base($connection), ['preview_id' => $preview->id, 'accept_missing' => false])->assertUnprocessable();
        $this->assertSame(24, SupplierProduct::where('connection_id', $connection->id)->where('available', true)->count());
        $this->activate($connection->fresh(), $preview, true);
        $this->assertSame(1, SupplierProduct::where('connection_id', $connection->id)->where('available', true)->count());
        $this->assertTrue($otherItem->fresh()->available);
        $this->travel(6)->minutes();
        $this->activate($connection->fresh(), $this->preview($connection->fresh()));
        $this->travel(6)->minutes();
        $html = $this->html($rows);
        $this->activate($connection->fresh(), $this->preview($connection->fresh()));
        $this->assertSame(24, SupplierProduct::where('connection_id', $connection->id)->where('available', true)->count());
        $this->assertSame(25, SupplierProduct::count());
    }

    public function test_shared_download_cache_requires_the_exact_account_url_and_profile(): void
    {
        $one = $this->connection();
        $same = $this->connection();
        $other = $this->connection();
        Http::fake(fn ($request) => Http::response($this->html([['001', 'Produk', str_contains($request->url(), 'fake-b') ? '2.000' : '1.000', 'Open']])));
        $this->preview($one);
        $this->preview($same);
        $preview = $this->preview($other, $this->input('fake-b'));
        Http::assertSentCount(2);
        $rows = app(OtomaxImports::class)->previewRows($preview, $preview->payload);
        $this->assertSame(2000, $rows[0]['cost']);
    }

    public function test_source_identity_change_invalidates_a_preview_and_file_import_endpoint_cannot_apply_url_rows(): void
    {
        $connection = $this->connection();
        Http::fake(fn () => Http::response($this->html()));
        $preview = $this->preview($connection);
        $this->post('/stores/'.$connection->store_id.'/otomax/imports/'.$preview->id.'/confirm', ['accept_partial' => false, 'missing' => 'disable'])->assertNotFound();
        $connection->update(['credentials' => [...$connection->credentials, 'member_id' => 'CHANGED']]);
        $this->postJson($this->base($connection), ['preview_id' => $preview->id, 'accept_missing' => true])->assertConflict();
        $this->assertDatabaseCount('supplier_products', 0);
        Queue::assertNothingPushed();
    }

    public function test_manual_sync_is_rate_limited_and_staff_import_permission_does_not_grant_url_editing(): void
    {
        $connection = $this->connection();
        Http::fake(fn () => Http::response($this->html()));
        $this->activate($connection, $this->preview($connection));
        $this->postJson($this->base($connection).'/sync')->assertUnprocessable();
        $staff = StoreStaff::create(['store_id' => $connection->store_id, 'name' => 'Operator', 'username' => 'url-sync', 'email' => 'sync@example.test', 'password' => 'fake-only-password', 'permissions' => ['suppliers.import']]);
        auth('web')->logout();
        $this->actingAs($staff, 'staff')->getJson($this->base($connection))->assertOk()->assertDontSee('fake-a');
        $this->postJson($this->base($connection).'/preview', $this->input())->assertForbidden();
        $this->travel(6)->minutes();
        $this->postJson($this->base($connection).'/sync')->assertRedirect();
        $this->assertSame(1, OtomaxImport::where('status', 'queued')->count());
    }

    public function test_optional_html_row_closures_recognize_zero_price_as_an_unconfigured_postpaid_candidate(): void
    {
        $html = '<table><tr><td colspan="4">AXIS<tr><td>Kode<td>Keterangan<td>Harga<td>Status<tr><td>001<td>Produk<td>0<td>Open</table>';
        $rows = app(OtomaxWebCatalog::class)->parse($html, OtomaxWebCatalog::defaults());
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['available']);
        $this->assertSame('postpaid', $rows[0]['kind']);
        $this->assertSame(0, $rows[0]['cost']);
        $this->assertStringContainsString('pasangan', $rows[0]['availability_reason']);
    }

    public function test_an_explicit_empty_table_requires_confirmation_before_disabling_the_last_product(): void
    {
        $connection = $this->connection();
        $html = $this->html();
        Http::fake(function () use (&$html) {
            return Http::response($html);
        });
        $this->activate($connection, $this->preview($connection));
        $this->travel(6)->minutes();
        $html = $this->html([]);
        $preview = $this->preview($connection->fresh());
        $this->assertSame(0, $preview->summary['valid']);
        $this->assertTrue($preview->summary['needs_confirmation']);
        $this->postJson($this->base($connection), ['preview_id' => $preview->id, 'accept_missing' => false])->assertUnprocessable();
        $this->assertTrue(SupplierProduct::where('connection_id', $connection->id)->sole()->available);
        $this->activate($connection->fresh(), $preview, true);
        $this->assertFalse(SupplierProduct::where('connection_id', $connection->id)->sole()->available);
    }

    public function test_signed_postpaid_commission_is_persisted_and_changes_are_counted(): void
    {
        $connection = $this->connection();
        $rows = [['CEKPLN30', 'Cek PLN', '0', 'Open'], ['BYRPLN30', 'Bayar PLN', '-500', 'Open']];
        $html = $this->html($rows);
        Http::fake(function () use (&$html) {
            return Http::response($html);
        });
        $preview = $this->preview($connection);
        $this->activate($connection, $preview);
        $pay = SupplierProduct::where('connection_id', $connection->id)->where('sku', 'BYRPLN30')->firstOrFail();
        $this->assertSame(0, $pay->cost);
        $this->assertSame(-500, $pay->billing['adjustment']);
        $this->assertSame('postpaid', $pay->kind);
        $this->assertTrue($pay->available);
        $parsed = app(OtomaxWebCatalog::class)->parse($this->html([['CEKPLN30', 'Cek PLN', '0', 'Open'], ['BYRPLN30', 'Bayar PLN', '-750', 'Open']]), OtomaxWebCatalog::defaults());
        $this->assertSame(1, app(OtomaxCatalogSync::class)->summary($connection, $parsed)['price_changed']);
        $check = SupplierProduct::where('connection_id', $connection->id)->where('sku', 'CEKPLN30')->firstOrFail();
        $product = CatalogProduct::create(['store_id' => $connection->store_id, 'code' => 'PLN-COMMISSION', 'name' => 'PLN komisi', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'sale_price' => 2025, 'markup' => '5%', 'auto_update' => true, 'active' => true]);
        ProductMapping::create(['store_id' => $connection->store_id, 'product_id' => $product->id, 'source' => 'otomax', 'source_key' => 'item:'.$pay->id, 'supplier_product_id' => $pay->id, 'active' => true, 'postpaid' => ['inquiry_id' => $check->id, 'admin' => 2000]]);
        $this->travel(6)->minutes();
        $html = $this->html([['CEKPLN30', 'Cek PLN', '0', 'Open'], ['BYRPLN30', 'Bayar PLN', '-400', 'Open']]);
        $this->activate($connection->fresh(), $this->preview($connection->fresh()));
        $this->assertSame(-400, $pay->fresh()->billing['adjustment']);
        $this->assertSame(1620, $product->fresh()->sale_price);

    }
}
