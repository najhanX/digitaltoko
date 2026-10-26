<?php

namespace Tests\Feature;

use App\Jobs\ImportOtomaxProducts;
use App\Jobs\ProcessCustomerOrder;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OtomaxEvent;
use App\Models\OtomaxImport;
use App\Models\OtomaxProfile;
use App\Models\OtomaxTicket;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\OtomaxClient;
use App\Services\OtomaxImports;
use App\Services\OtomaxParser;
use App\Services\SupplierWallets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class OtomaxTest extends TestCase
{
    use RefreshDatabase;

    public function test_signature_vectors_preserve_case_and_use_binary_sha1_base64url(): void
    {
        $this->assertSame('1JfNlQ7xGM9scGANRYuQBxlukCM', OtomaxClient::sign('DEMO', '1111', 'test-only', 'transaction', ['product' => 'TEST', 'dest' => '08123456789', 'refID' => 'REF1']));
        $this->assertSame('3zFOqcsZYP91H_4lpTyuvLor1sU', OtomaxClient::sign('DEMO', '1111', 'test-only', 'balance'));
        $this->assertSame('uLepH8CkjhW6rFZTtJjNXPjW3Ns', OtomaxClient::sign('DEMO', '1111', 'test-only', 'ticket', ['amount' => 50000]));
    }

    public function test_callback_updates_supplier_balance_by_event_time_without_late_callback_regression(): void
    {
        $connection = $this->connection();
        $order = $this->order($connection);
        Http::fake(fn () => Http::response('#12345 TEST.08123456789 akan diproses @12:00. Saldo 100.000 - 10.000 = 90.000'));
        app(CustomerFulfillment::class)->run($order);
        $attempt = $order->attempts()->sole();
        $url = '/api/commerce/otomax/'.$connection->id.'/'.str_repeat('a', 48);
        $time = now('Asia/Jakarta')->subMinute()->startOfSecond();
        $message = fn ($amount, $at) => '#12345 TEST.08123456789 SUKSES. SN/Ref: RESULT. Saldo 100.000 - 10.000 = '.$amount.' @'.$at->format('d/m/Y H:i:s').' R#'.$attempt->reference;
        foreach ([[90000, $time], [95000, $time->copy()->subSecond()]] as [$balance, $at]) {
            $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson($url.'?'.http_build_query(['refid' => $attempt->reference, 'status' => '20', 'price' => 10000, 'message' => $message($balance, $at)]))->assertOk();
            $this->assertSame(90000, app(SupplierWallets::class)->account($connection)->balance);
        }
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('callback', app(SupplierWallets::class)->account($connection)->source);
    }

    public function test_definitive_failure_of_final_source_fails_order_and_refunds_once(): void
    {
        $connection = $this->connection();
        $order = $this->order($connection);
        Http::fake(fn ($request) => Http::response(['status' => '40', 'refid' => $request['refID'], 'price' => '10000', 'message' => 'Gagal pasti']));
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(100000, (int) $order->customer->fresh()->balance);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame(100000, (int) $order->customer->fresh()->balance);
        Http::assertSentCount(1);
    }

    public function test_unavailable_otomax_source_before_dispatch_fails_without_request_and_refunds_only_once(): void
    {
        $connection = $this->connection();
        $order = $this->order($connection);
        SupplierProduct::where('connection_id', $connection->id)->update(['available' => false]);
        $engine = app(CustomerFulfillment::class);
        $engine->run($order);
        $engine->run($order->fresh());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(100000, $order->customer->fresh()->balance);
        $attempt = $order->attempts()->sole();
        $this->assertSame('failed', $attempt->status);
        $this->assertNull($attempt->last_checked_at);
        $this->assertStringContainsString('tidak tersedia', $attempt->message);
        Http::assertNothingSent();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false, 'suppliers.cache_store' => 'array']);
        Http::preventStrayRequests();
        Queue::fake([ProcessCustomerOrder::class]);
        Cache::flush();
        Storage::fake('local');
    }

    private function shop(string $plan = 'galaxy'): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.Str::random(8)])->id, 'slug' => 'demo-'.Str::lower(Str::random(8)), 'name' => 'Toko uji', 'category' => 'full', 'plan' => $plan, 'status' => 'active', 'expires_at' => now()->addDays(30), 'template' => 'aurora']);
    }

    private function connection(?Store $store = null): SupplierConnection
    {
        $store ??= $this->shop();
        $profile = OtomaxProfile::create(['store_id' => $store->id, 'type' => 'response', 'name' => 'Format A', 'config' => OtomaxParser::defaults()]);

        return SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => Str::uuid(), 'name' => 'Supplier uji', 'response_profile_id' => $profile->id, 'enabled' => true, 'credentials' => ['member_id' => 'DEMO', 'pin' => '1111', 'password' => 'test-only', 'callback_token' => str_repeat('a', 48)], 'settings' => ['base_url' => 'http://8.8.8.8:6969', 'callback_ips' => ['8.8.8.8'], 'transaction_path' => '/trx', 'balance_path' => '/balance', 'ticket_path' => '/ticket', 'status_path' => '', 'catalog_hours' => 24, 'ticket_cmd' => false, 'price_limit_ack' => true]]);
    }

    private function base(Store $s): string
    {
        return '/stores/'.$s->id.'/otomax';
    }

    private function message(string $ref, string $status = 'SUKSES'): string
    {
        return '#12345 TEST.08123456789 '.$status.'. SN/Ref: 001/hasil. Saldo 100.000 - 10.000 = 90.000 @21/10 16:52:34 R#'.$ref;
    }

    private function order(SupplierConnection $c): CustomerOrder
    {
        $s = $c->store;
        $customer = Customer::create(['store_id' => $s->id, 'name' => 'Pelanggan uji', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'balance' => 100000, 'sandbox_balance' => 100000]);
        $item = SupplierProduct::create(['store_id' => $s->id, 'connection_id' => $c->id, 'sku' => 'TEST', 'mode' => 'prepaid', 'name' => 'Produk uji', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'Umum', 'cost' => 10000, 'available' => true, 'observed_at' => now()]);
        $p = CatalogProduct::create(['store_id' => $s->id, 'code' => 'TEST', 'name' => 'Produk uji', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'Umum', 'active' => true, 'sale_price' => 15000, 'markup' => '5000', 'auto_update' => true]);
        ProductMapping::create(['store_id' => $s->id, 'product_id' => $p->id, 'supplier_product_id' => $item->id, 'source' => 'otomax', 'source_key' => 'item:'.$item->id]);

        return app(CustomerCommerce::class)->begin($s, ['purpose' => 'product', 'product_id' => $p->id, 'phone' => $customer->phone, 'destination' => '08123456789', 'payment' => 'wallet', 'idempotency_key' => (string) Str::uuid()], $customer);
    }

    public function test_transaction_response_updates_source_price_and_only_automatic_sale_prices(): void
    {
        $connection = $this->connection();
        $order = $this->order($connection);
        $item = SupplierProduct::where('connection_id', $connection->id)->sole();
        $observed = $item->observed_at->toISOString();
        $fixed = CatalogProduct::create(['store_id' => $connection->store_id, 'code' => 'FIXED', 'name' => 'Fixed price', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'Umum', 'active' => true, 'sale_price' => 20000, 'markup' => '5000', 'auto_update' => false]);
        ProductMapping::create(['store_id' => $connection->store_id, 'product_id' => $fixed->id, 'supplier_product_id' => $item->id, 'source' => 'otomax', 'source_key' => 'item:'.$item->id]);
        Http::fake(fn () => Http::response('#123 TEST.08123456789 akan diproses @12:00. Saldo 50.000 - 12.000 = 38.000'));
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame(12000, $item->fresh()->cost);
        $this->assertSame(17000, CatalogProduct::findOrFail($order->product_id)->sale_price);
        $this->assertSame(20000, $fixed->fresh()->sale_price);
        $this->assertSame(15000, $order->fresh()->amount);
        $this->assertSame(85000, $order->customer->fresh()->balance);
        $this->assertSame($observed, $item->fresh()->observed_at->toISOString());
        $this->assertSame('pending', $order->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_callback_updates_price_with_category_markup_without_changing_invoice_or_other_supplier(): void
    {
        $connection = $this->connection();
        $order = $this->order($connection);
        CatalogProduct::findOrFail($order->product_id)->update(['markup' => null, 'auto_update' => null]);
        PricingRule::create(['store_id' => $connection->store_id, 'scope' => 'category', 'key' => 'umum', 'markup' => '10%', 'auto_update' => true]);
        $other = $this->connection();
        $this->order($other);
        Http::fake(fn () => Http::response('#123 TEST.08123456789 akan diproses @12:00. Saldo 50.000 - 10.000 = 40.000'));
        app(CustomerFulfillment::class)->run($order);
        $reference = $order->attempts()->first()->reference;
        $url = '/api/commerce/otomax/'.$connection->id.'/'.str_repeat('a', 48);
        $callback = ['refid' => $reference, 'status' => '20', 'price' => '12000', 'message' => str_replace('10.000', '12.000', $this->message($reference))];
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson($url.'?'.http_build_query($callback))->assertOk();
        $this->getJson($url.'?'.http_build_query($callback))->assertOk();
        $this->assertSame(12000, SupplierProduct::where('connection_id', $connection->id)->sole()->cost);
        $this->assertSame(13200, CatalogProduct::findOrFail($order->product_id)->sale_price);
        $this->assertSame(10000, SupplierProduct::where('connection_id', $other->id)->sole()->cost);
        $this->assertSame(15000, $order->fresh()->amount);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(85000, $order->customer->fresh()->balance);
    }

    public function test_invalid_mismatched_and_simulated_responses_do_not_update_source_price(): void
    {
        $connection = $this->connection();
        $order = $this->order($connection);
        $item = SupplierProduct::where('connection_id', $connection->id)->sole();
        Http::fake(fn () => Http::response('#123 WRONG.08123456789 akan diproses @12:00. Saldo 50.000 - 12.000 = 38.000'));
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame(10000, $item->fresh()->cost);
        $attempt = $order->attempts()->first();
        $url = '/api/commerce/otomax/'.$connection->id.'/'.str_repeat('a', 48);
        foreach (['0', '-1', 'abc', '1000000000001'] as $price) {
            $callback = ['refid' => $attempt->reference, 'status' => '40', 'price' => $price, 'message' => 'Gagal pasti'];
            $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson($url.'?'.http_build_query($callback))->assertOk();
            $this->assertSame(10000, $item->fresh()->cost);
        }
        $sandboxConnection = $this->connection();
        $sandboxConnection->store->update(['sandbox' => true]);
        $sandboxOrder = $this->order($sandboxConnection);
        $sandboxAttempt = $sandboxOrder->attempts()->first();
        $sandboxAttempt->update(['cost' => 12000]);
        app(CustomerFulfillment::class)->run($sandboxOrder);
        $this->assertSame(10000, SupplierProduct::where('connection_id', $sandboxConnection->id)->sole()->cost);
    }

    public function test_parser_extracts_fields_and_rejects_conflicting_patterns(): void
    {
        $p = app(OtomaxParser::class);
        $c = OtomaxParser::defaults();
        $p->validate($c);
        $r = $p->parse($c, 'callback', ['refid' => 'REF1', 'status' => '20', 'price' => '10000', 'message' => $this->message('REF1')]);
        $this->assertSame('success', $r['status']);
        $this->assertNull($r['error']);
        $this->assertSame('001/hasil', $r['fields']['sn']);
        $this->assertSame('12345', $r['fields']['id_transaksi']);
        $c['rules'][] = [...$c['rules'][0], 'outcome' => 'failed', 'name' => 'Konflik'];
        $this->assertNotNull($p->parse($c, 'callback', $this->message('REF1'))['error']);
        $this->assertNotNull($p->parse(OtomaxParser::defaults(), 'callback', ['refid' => 'LAIN', 'status' => '20', 'price' => '10000', 'message' => $this->message('REF1')])['error']);
    }

    public function test_unknown_responses_never_become_definitive_failure_and_multiple_success_patterns_work(): void
    {
        $c = OtomaxParser::defaults();
        $c['rules'][] = ['event' => 'callback', 'active' => true, 'name' => 'Sukses lain', 'outcome' => 'success', 'template' => 'BERHASIL [refid] SN:[sn]'];
        $p = app(OtomaxParser::class);
        $this->assertSame('success', $p->parse($c, 'callback', 'BERHASIL REF1 SN:00001')['status']);
        $this->assertSame('pending', $p->parse($c, 'callback', 'GAGAL SUKSES??')['status']);
        $this->assertNotNull($p->parse($c, 'callback', 'GAGAL SUKSES??')['error']);
    }

    public function test_multi_connections_scopes_permissions_and_credentials_are_hidden(): void
    {
        $c = $this->connection();
        $s = $c->store;
        $other = $this->connection($s);
        $this->actingAs($s->owner)->get($this->base($s))->assertOk()->assertDontSee('test-only')->assertDontSee(str_repeat('a', 48));
        $this->assertSame(2, SupplierConnection::where('store_id', $s->id)->count());
        $outsider = $this->shop();
        $this->actingAs($outsider->owner)->postJson($this->base($outsider).'/connections/'.$c->id.'/callback-url')->assertNotFound();
        $staff = StoreStaff::create(['store_id' => $s->id, 'name' => 'Viewer', 'username' => 'viewer', 'email' => 'viewer@example.test', 'password' => bcrypt('test-only'), 'active' => true, 'permissions' => ['products.view']]);
        $this->actingAs($staff, 'staff')->postJson($this->base($s).'/connections/'.$c->id.'/callback-url')->assertForbidden();
    }

    public function test_legacy_stock_plan_can_access_otomax(): void
    {
        $s = $this->shop('space');
        $this->actingAs($s->owner)->get($this->base($s))->assertOk();
    }

    public function test_private_endpoint_is_rejected_without_network(): void
    {
        foreach (['http://127.0.0.1:8000', 'http://169.254.169.254', 'http://192.168.1.1', 'file:///etc/passwd', 'http://user:pass@8.8.8.8'] as $url) {
            try {
                app(OtomaxClient::class)->endpoint(['base_url' => $url]);
                $this->fail('Unsafe endpoint accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }Http::assertNothingSent();
    }

    public function test_connection_save_encrypted_and_profile_revision_conflict(): void
    {
        $c = $this->connection();
        $s = $c->store;
        $this->actingAs($s->owner)->patchJson($this->base($s).'/connections/'.$c->id, ['name' => 'Baru', 'member_id' => 'DEMO', 'pin' => '', 'password' => '', 'enabled' => true, 'response_profile_id' => $c->response_profile_id, 'settings' => $c->settings])->assertRedirect();
        $this->assertSame('test-only', $c->fresh()->credentials['password']);
        $this->assertStringNotContainsString('test-only', DB::table('supplier_connections')->where('id', $c->id)->value('credentials'));
        $this->patchJson($this->base($s).'/profiles/'.$c->response_profile_id, ['type' => 'response', 'name' => 'Baru', 'version' => 99, 'config' => OtomaxParser::defaults()])->assertConflict();
        Http::assertNothingSent();
    }

    public function test_csv_preview_reimport_is_idempotent_and_price_zero_rejected(): void
    {
        $c = $this->connection();
        $s = $c->store;
        $this->actingAs($s->owner);
        $config = OtomaxImports::defaults();
        $config['header_row'] = 1;
        $config['grouped'] = false;
        $config['prefix_group'] = false;
        $config['columns']['status'] = 'D';
        $csv = "SKU;Nama;Modal;Status\nA01;Produk A;8.700;Open\nA02;Produk B;;Open\nA03;Produk C;0;Open\nA04;Produk D;9.000;Gangguan\n";
        $r = $this->postJson($this->base($s).'/connections/'.$c->id.'/preview', ['file' => UploadedFile::fake()->createWithContent('produk.csv', $csv), 'config' => $config])->assertOk()->assertJsonPath('summary.valid', 2)->assertJsonPath('summary.rejected', 2)->assertJsonPath('sample.0.cost', 8700);
        $id = $r->json('id');
        $this->postJson($this->base($s).'/imports/'.$id.'/confirm', ['accept_partial' => false, 'missing' => 'keep'])->assertUnprocessable();
        $this->postJson($this->base($s).'/imports/'.$id.'/confirm', ['accept_partial' => true, 'missing' => 'keep'])->assertRedirect();
        $this->assertSame('completed', OtomaxImport::find($id)->status);
        $this->assertSame(2, SupplierProduct::where('connection_id', $c->id)->count());
        $this->assertFalse((bool) SupplierProduct::where('sku', 'A04')->value('available'));
        $this->postJson($this->base($s).'/imports/'.$id.'/confirm', ['accept_partial' => true, 'missing' => 'keep'])->assertRedirect();
        $this->assertSame(2, SupplierProduct::where('connection_id', $c->id)->count());
    }

    public function test_large_excel_preview_keeps_database_packets_small_and_imports_all_rows(): void
    {
        $connection = $this->connection();
        $this->actingAs($connection->store->owner);
        Queue::fake([ImportOtomaxProducts::class]);
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Kode', 'Nama', 'Harga', 'Status']]);
        for ($i = 1; $i <= 4000; $i++) {
            $sheet->fromArray([['SKU'.$i, str_repeat('Produk digital ', 9).$i, 1000 + $i, 'Open']], null, 'A'.($i + 1));
        }
        $path = tempnam(sys_get_temp_dir(), 'oto');
        $largestPacket = 0;
        DB::listen(function ($query) use (&$largestPacket) {
            if (str_contains($query->sql, 'otomax_imports') && preg_match('/^(insert|update)/i', $query->sql)) {
                $bytes = strlen($query->sql) + array_sum(array_map(fn ($binding) => is_string($binding) ? strlen($binding) : 8, $query->bindings));
                $largestPacket = max($largestPacket, $bytes);
            }
        });
        try {
            (new Xlsx($book))->save($path);
            $config = [...OtomaxImports::defaults(), 'header_row' => 1, 'grouped' => false, 'prefix_group' => false];
            $config['columns']['status'] = 'D';
            $url = $this->base($connection->store).'/connections/'.$connection->id.'/preview';
            $preview = $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('large.xlsx', file_get_contents($path)), 'config' => $config])
                ->assertOk()->assertJsonPath('summary.valid', 4000)->assertJsonCount(50, 'sample');
            $record = OtomaxImport::findOrFail($preview->json('id'));
            $payload = $record->payload;
            $old = SupplierProduct::create(['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'sku' => 'OLD-SKU', 'name' => 'Produk lama', 'kind' => 'ppob', 'mode' => 'prepaid', 'service' => 'Produk digital', 'category' => 'Umum', 'cost' => 1000, 'available' => true, 'observed_at' => now()]);
            $this->assertArrayNotHasKey('rows', $payload);
            $this->assertArrayNotHasKey('errors', $payload);
            $encrypted = Storage::disk('local')->get($payload['rows_path']);
            $this->assertGreaterThan(1024 * 1024, strlen($encrypted));
            $this->assertStringNotContainsString('SKU4000', $encrypted);
            $this->assertSame(4000, count(app(OtomaxImports::class)->previewRows($record, $payload)));
            $this->postJson($this->base($connection->store).'/imports/'.$record->id.'/confirm', ['accept_partial' => false, 'missing' => 'disable'])->assertRedirect();
            app(OtomaxImports::class)->apply($record->fresh());
            $this->assertSame('completed', $record->fresh()->status);
            $this->assertSame(4001, SupplierProduct::where('connection_id', $connection->id)->count());
            $this->assertFalse($old->fresh()->available);
            Storage::disk('local')->assertMissing($payload['rows_path']);
            Storage::disk('local')->assertMissing($payload['path']);
            $this->assertLessThan(32768, $largestPacket);
            Http::assertNothingSent();
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_repreview_replaces_encrypted_rows_and_corrupt_data_cannot_change_products(): void
    {
        $connection = $this->connection();
        $this->actingAs($connection->store->owner);
        Queue::fake([ImportOtomaxProducts::class]);
        $config = [...OtomaxImports::defaults(), 'header_row' => 1, 'grouped' => false, 'prefix_group' => false];
        $config['columns']['status'] = 'D';
        $url = $this->base($connection->store).'/connections/'.$connection->id.'/preview';
        $first = $this->postJson($url, ['file' => UploadedFile::fake()->createWithContent('prices.csv', "Kode;Nama;Harga;Status\nA01;Produk;1000;Open\n"), 'config' => $config])->assertOk();
        $record = OtomaxImport::findOrFail($first->json('id'));
        $original = $record->payload;
        $this->postJson($url, ['import_id' => $record->id, 'config' => $config])->assertOk();
        $payload = $record->fresh()->payload;
        $this->assertNotSame($original['rows_path'], $payload['rows_path']);
        Storage::disk('local')->assertMissing($original['rows_path']);
        Storage::disk('local')->put($payload['rows_path'], 'corrupt');
        $this->postJson($this->base($connection->store).'/imports/'.$record->id.'/confirm', ['accept_partial' => false, 'missing' => 'keep'])->assertRedirect();
        app(OtomaxImports::class)->apply($record->fresh());
        $this->assertSame('failed', $record->fresh()->status);
        $this->assertSame(0, SupplierProduct::where('connection_id', $connection->id)->count());
        app(OtomaxImports::class)->deleteFiles($record->fresh());
        Storage::disk('local')->assertMissing($payload['rows_path']);
        Storage::disk('local')->assertMissing($payload['path']);
    }

    public function test_preview_files_are_bound_to_their_store_and_import_and_legacy_rows_remain_readable(): void
    {
        $connection = $this->connection();
        $service = app(OtomaxImports::class);
        $first = OtomaxImport::create(['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'filename' => 'old.csv', 'file_hash' => str_repeat('a', 64), 'payload' => ['rows' => [['sku' => 'OLD']]], 'summary' => [], 'status' => 'draft', 'expires_at' => now()->addHour()]);
        $this->assertSame([['sku' => 'OLD']], $service->previewRows($first, $first->payload));
        $service->savePreview($first, ['sandbox' => false], ['valid' => 1], [['sku' => 'NEW']]);
        $payload = $first->fresh()->payload;
        $other = clone $first;
        $other->id = (string) Str::uuid();
        try {
            $service->previewRows($other, $payload);
            $this->fail('Another import must not read this file.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Lokasi pratinjau tidak valid.', $error->getMessage());
        }
        Storage::disk('local')->assertExists($payload['rows_path']);
    }

    public function test_group_headers_and_duplicates_are_not_products(): void
    {
        $rows = [['A' => 'judul'], [], ['A' => 'Kode', 'B' => 'Nama', 'C' => 'Harga', 'F' => 'Status'], ['A' => 'Kelompok'], ['A' => '001', 'B' => 'Nama', 'C' => '1.000', 'F' => 'Open'], ['A' => '002', 'B' => 'Dua', 'C' => '2.000', 'F' => 'Open'], ['A' => '002', 'B' => 'Duplikat', 'C' => '2.000', 'F' => 'Open']];
        $r = app(OtomaxImports::class)->normalize($rows, OtomaxImports::defaults());
        $this->assertSame(1, $r['summary']['valid']);
        $this->assertSame('001', $r['rows'][0]['sku']);
        $this->assertSame('Kelompok', $r['rows'][0]['category']);
        $this->assertNull($r['rows'][0]['quantity']);
        $rows[] = ['A' => 'abc', 'B' => 'Tiga', 'C' => '3.000', 'F' => 'Open'];
        $rows[] = ['A' => 'ABC', 'B' => 'Duplikat kapital', 'C' => '3.000', 'F' => 'Open'];
        $this->assertSame(1, app(OtomaxImports::class)->normalize($rows, OtomaxImports::defaults())['summary']['valid']);
    }

    public function test_files_without_header_or_status_need_explicit_availability_choice(): void
    {
        $config = OtomaxImports::defaults();
        $config['header_row'] = 0;
        $config['grouped'] = false;
        $config['prefix_group'] = false;
        $config['columns']['status'] = '';
        $rows = [['A' => 'abc', 'B' => 'Produk', 'C' => '1000']];
        $service = app(OtomaxImports::class);
        $result = $service->normalize($rows, $config);
        $this->assertSame(1, $result['summary']['valid']);
        $this->assertSame('ABC', $result['rows'][0]['sku']);
        $this->assertFalse($result['rows'][0]['available']);
        $config['default_available'] = true;
        $this->assertTrue($service->normalize($rows, $config)['rows'][0]['available']);
    }

    public function test_reimport_updates_source_without_duplicating_product_or_overwriting_markup(): void
    {
        $c = $this->connection();
        $s = $c->store;
        $this->actingAs($s->owner);
        $config = OtomaxImports::defaults();
        $config['header_row'] = 1;
        $config['grouped'] = false;
        $config['prefix_group'] = false;
        $config['columns']['status'] = 'D';
        foreach ([1000, 1200] as $price) {
            $this->travel(1)->seconds();
            $preview = $this->postJson($this->base($s).'/connections/'.$c->id.'/preview', ['file' => UploadedFile::fake()->createWithContent('harga.csv', "Kode;Nama;Harga;Status\na01;Produk;".$price.";Open\n"), 'config' => $config])->assertOk();
            $this->postJson($this->base($s).'/imports/'.$preview->json('id').'/confirm', ['accept_partial' => false, 'missing' => 'keep'])->assertRedirect();
            $source = SupplierProduct::where('connection_id', $c->id)->sole();
            $this->post('/stores/'.$s->id.'/products/import', ['ids' => [$source->id]])->assertRedirect();
            if ($price === 1000) {
                $sourceId = $source->id;
                CatalogProduct::where('store_id', $s->id)->sole()->update(['markup' => '500', 'auto_update' => true]);
            }
        }
        $this->assertSame($sourceId, $source->id);
        $this->assertSame(1200, $source->cost);
        $this->assertSame(1, CatalogProduct::where('store_id', $s->id)->count());
        $product = CatalogProduct::where('store_id', $s->id)->sole();
        $this->assertSame('O-A01', $product->code);
        $this->assertSame('500', $product->markup);
        $this->assertSame(1700, $product->sale_price);
        Http::assertNothingSent();
    }

    public function test_same_day_resubmission_and_callback_reconcile_refund_once(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'Asia/Jakarta'));
        $c = $this->connection();
        $order = $this->order($c);
        Http::fake(['*' => Http::response('#12345 TEST.08123456789 akan diproses @16:46. Saldo 100.000 - 10.000 = 90.000')]);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('pending', $order->fresh()->status);
        $a = $order->attempts()->first();
        $this->travel(2)->minutes();
        app(CustomerFulfillment::class)->retrySupplier($order->fresh());
        Http::assertSentCount(2);
        $this->assertSame(1, $order->attempts()->count());
        $this->assertCount(1, array_unique(array_map(fn ($entry) => $entry[0]['refID'], Http::recorded()->all())));
        $url = '/api/commerce/otomax/'.$c->id.'/'.str_repeat('a', 48);
        $data = ['refid' => $a->reference, 'status' => '20', 'price' => '10000', 'message' => $this->message($a->reference)];
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson($url.'?'.http_build_query($data))->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('12345', $a->fresh()->provider_id);
        $this->getJson($url.'?'.http_build_query($data))->assertOk();
        $this->assertSame(85000, (int) $order->customer->fresh()->balance);
        $data = ['refid' => $a->reference, 'status' => '40', 'price' => '10000', 'message' => 'Gagal terkonfirmasi'];
        $this->getJson($url.'?'.http_build_query($data))->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(100000, (int) $order->customer->fresh()->balance);
        $this->getJson($url.'?'.http_build_query($data))->assertOk();
        $this->assertSame(100000, (int) $order->customer->fresh()->balance);
        $data = ['refid' => $a->reference, 'status' => '20', 'price' => '10000', 'message' => $this->message($a->reference)];
        $this->getJson($url.'?'.http_build_query($data))->assertOk();
        $this->assertSame(85000, (int) $order->customer->fresh()->balance);
    }

    public function test_otomax_same_day_retry_reuses_request_and_reads_duplicate_response(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'Asia/Jakarta'));
        $connection = $this->connection();
        $order = $this->order($connection);
        $count = 0;
        Http::fake(function ($request) use (&$count) {
            $count++;
            if ($count === 1) {
                return Http::response('#12345 TEST.08123456789 akan diproses @12:00. Saldo 100.000 - 10.000 = 90.000');
            }
            $message = 'TEST.08123456789 sdh pernah jam 12:00 Hrg 10.000, status Sukses. SN/Ref: 001/hasil. Trx ke-2/hr: TEST.2.08123456789.pin. Saldo 90.000 #12345 R#'.$request['refID'];

            return Http::response(http_build_query(['status' => '20', 'message' => $message]));
        });
        $engine = app(CustomerFulfillment::class);
        $engine->run($order);
        $attempt = $order->attempts()->first();
        $firstSentAt = $attempt->request_snapshot['otomax_first_sent_at'];
        $engine->run($order->fresh());
        Http::assertSentCount(1);
        $this->travel(2)->minutes();
        $engine->retrySupplier($order->fresh());
        Http::assertSentCount(2);
        $requests = Http::recorded()->all();
        $this->assertSame($requests[0][0]->url(), $requests[1][0]->url());
        $this->assertSame($attempt->reference, $requests[1][0]['refID']);
        $this->assertSame($firstSentAt, $attempt->fresh()->request_snapshot['otomax_first_sent_at']);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('001/hasil', $attempt->fresh()->result);
        $this->assertSame('12345', $attempt->fresh()->provider_id);
        $this->assertSame(10000, $attempt->fresh()->cost);
        $this->assertSame(85000, $order->customer->fresh()->balance);
        $this->assertSame(1, $order->attempts()->count());
        $engine->run($order->fresh());
        Http::assertSentCount(2);
    }

    public function test_otomax_retry_stops_at_wib_midnight_and_still_accepts_callback(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 23:59:00', 'Asia/Jakarta'));
        $connection = $this->connection();
        $order = $this->order($connection);
        Http::fake(fn () => Http::response('#12345 TEST.08123456789 akan diproses @23:59. Saldo 100.000 - 10.000 = 90.000'));
        app(CustomerFulfillment::class)->run($order);
        $attempt = $order->attempts()->first();
        $this->travel(2)->minutes();
        app(CustomerFulfillment::class)->run($order->fresh());
        Http::assertSentCount(1);
        try {
            app(CustomerFulfillment::class)->retrySupplier($order->fresh());
            $this->fail('Retry across midnight must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('hari pengiriman pertama', json_encode($exception->errors()));
        }
        $this->assertSame('pending', $order->fresh()->status);
        $snapshot = $attempt->fresh()->request_snapshot;
        unset($snapshot['otomax_first_sent_at']);
        $attempt->update(['request_snapshot' => $snapshot]);
        $this->travel(2)->minutes();
        try {
            app(CustomerFulfillment::class)->retrySupplier($order->fresh());
            $this->fail('Legacy retry across midnight must be rejected.');
        } catch (ValidationException) {
            Http::assertSentCount(1);
        }
        $url = '/api/commerce/otomax/'.$connection->id.'/'.str_repeat('a', 48);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson($url.'?'.http_build_query([
            'refid' => $attempt->reference, 'status' => '20', 'price' => '10000', 'message' => $this->message($attempt->reference),
        ]))->assertOk();
        $this->assertSame('success', $order->fresh()->status);
    }

    public function test_otomax_duplicate_response_does_not_override_conflicting_query_status(): void
    {
        $message = 'CEKGOJEK.08123456789 sdh pernah jam 16:52 Hrg 5, status Sukses. SN/Ref: 628123456789. Trx ke-2/hr: CEKGOJEK.2.08123456789.pin. Saldo 1.011 #264447712 R#TR12342';
        $parser = app(OtomaxParser::class);
        $result = $parser->parse(OtomaxParser::defaults(), 'transaction', ['status' => '20', 'message' => $message]);
        $this->assertNull($result['error']);
        $this->assertSame('success', $result['status']);
        $this->assertSame('5', $result['fields']['harga']);
        $this->assertSame('628123456789', $result['fields']['sn']);
        $this->assertSame('TR12342', $result['fields']['refid']);
        $conflict = $parser->parse(OtomaxParser::defaults(), 'transaction', ['status' => '40', 'message' => $message]);
        $this->assertNotNull($conflict['error']);
        $this->assertSame('pending', $conflict['status']);
    }

    public function test_supplier_retry_requires_explicit_panel_action_and_management_permission(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'Asia/Jakarta'));
        $connection = $this->connection();
        $order = $this->order($connection);
        Http::fake(fn () => Http::response('#12345 TEST.08123456789 akan diproses @12:00. Saldo 100.000 - 10.000 = 90.000'));
        app(CustomerFulfillment::class)->run($order);
        $url = '/stores/'.$connection->store_id.'/orders/'.$order->id;
        $this->actingAs($connection->store->owner)->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('canRetrySupplier', true));
        $this->post($url, ['action' => 'retry_supplier'])->assertSessionHasErrors('commerce');
        Http::assertSentCount(1);
        $this->travel(2)->minutes();
        $this->artisan('digitaltoko:commerce')->assertSuccessful();
        app(CustomerFulfillment::class)->run($order->fresh());
        Http::assertSentCount(1);
        $staff = StoreStaff::create(['store_id' => $connection->store_id, 'username' => 'retry-reader', 'email' => 'retry-reader@example.test', 'name' => 'Staff uji', 'password' => 'test-only', 'permissions' => ['orders.view'], 'active' => true]);
        $this->app['auth']->guard('web')->logout();
        $this->actingAs($staff, 'staff')->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('canRetrySupplier', false));
        $this->post($url, ['action' => 'retry_supplier'])->assertForbidden();
        Http::assertSentCount(1);
        $staff->update(['permissions' => ['orders.view', 'orders.manage']]);
        $this->actingAs($staff->fresh(), 'staff')->post($url, ['action' => 'retry_supplier'])->assertSessionHasNoErrors();
        Http::assertSentCount(2);
        $other = $this->shop();
        $this->actingAs($other->owner)->post($url, ['action' => 'retry_supplier'])->assertForbidden();
        Http::assertSentCount(2);
    }

    public function test_manual_supplier_retry_reconciles_terminal_status_and_sn_without_duplicate_refund(): void
    {
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'Asia/Jakarta'));
        $connection = $this->connection();
        $order = $this->order($connection);
        $responseSn = null;
        Http::fake(function ($request) use (&$responseSn) {
            if ($responseSn === null) {
                return Http::response('#12345 TEST.08123456789 akan diproses @12:00. Saldo 100.000 - 10.000 = 90.000');
            }

            return Http::response(['status' => '20', 'price' => '10000', 'refid' => $request['refID'], 'message' => 'TEST.08123456789 sdh pernah jam 12:00 Hrg 10.000, status Sukses. SN/Ref: '.$responseSn.'. Trx ke-2/hr: TEST.2.08123456789.pin. Saldo 90.000 #12345 R#'.$request['refID']]);
        });
        app(CustomerFulfillment::class)->run($order);
        $url = '/stores/'.$connection->store_id.'/orders/'.$order->id;
        $this->actingAs($connection->store->owner)->post($url, ['action' => 'manual', 'status' => 'failed', 'note' => 'Pengujian gagal manual', 'result' => ''])->assertSessionHasNoErrors();
        $this->assertSame(100000, $order->customer->fresh()->balance);
        $responseSn = '001/hasil';
        $this->travel(2)->minutes();
        $this->post($url, ['action' => 'retry_supplier'])->assertSessionHasNoErrors();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('001/hasil', $order->fresh()->result);
        $this->assertSame(85000, $order->customer->fresh()->balance);
        $this->travel(2)->minutes();
        $this->post($url, ['action' => 'retry_supplier'])->assertSessionHasNoErrors();
        $this->assertSame(85000, $order->customer->fresh()->balance);
        $responseSn = 'SN-BARU';
        $this->travel(2)->minutes();
        $this->post($url, ['action' => 'retry_supplier'])->assertSessionHasNoErrors();
        $this->assertSame('SN-BARU', $order->fresh()->result);
        $this->assertSame(85000, $order->customer->fresh()->balance);
        $this->assertSame(1, $order->attempts()->count());
    }

    public function test_attempt_detail_includes_supplier_name_and_scopes_legacy_lookup_to_store(): void
    {
        $connection = $this->connection();
        $order = $this->order($connection);
        $url = '/stores/'.$connection->store_id.'/orders/'.$order->id;
        $connection->update(['name' => 'Nama baru supplier']);
        $this->actingAs($connection->store->owner)->get($url)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('attempts.0.supplier_name', 'Supplier uji')->missing('attempts.0.request_snapshot'));
        $attempt = $order->attempts()->first();
        $snapshot = $attempt->request_snapshot;
        unset($snapshot['otomax']['connection_name']);
        $attempt->update(['request_snapshot' => $snapshot]);
        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('attempts.0.supplier_name', 'Nama baru supplier'));
        $foreign = $this->connection();
        $snapshot['connection_id'] = $foreign->id;
        $attempt->update(['request_snapshot' => $snapshot]);
        $this->get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('attempts.0.supplier_name', null));
    }

    public function test_complete_supplier_messages_are_preserved_in_owner_attempt_details(): void
    {
        $connection = $this->connection();
        $order = $this->order($connection);
        $initial = "Jawaban awal belum dikenal\n".str_repeat('keterangan ', 70);
        Http::fake(fn () => Http::response(['message' => $initial]));
        app(CustomerFulfillment::class)->run($order);
        $attempt = $order->attempts()->first();
        $this->assertSame($initial, $attempt->request_snapshot['supplier_response']['message']);
        $this->assertNotNull($attempt->request_snapshot['supplier_response']['parser_error']);
        $url = '/api/commerce/otomax/'.$connection->id.'/'.str_repeat('a', 48);
        $message = "Format callback belum dikenal\n".rtrim(str_repeat('pesan lengkap ', 70));
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->getJson($url.'?'.http_build_query(['refid' => $attempt->reference, 'message' => $message]))
            ->assertOk()->assertJsonPath('status', 'REVIEW');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame($message, $attempt->fresh()->request_snapshot['supplier_response']['message']);
        $event = OtomaxEvent::where('reference', $attempt->reference)->firstOrFail();
        $this->assertSame($message, $event->data['message']);
        $this->assertStringNotContainsString($message, (string) DB::table('otomax_events')->where('id', $event->id)->value('data'));
        $this->actingAs($connection->store->owner)->get($this->base($connection->store).'?tab=events')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('items.0.reference', $attempt->reference)
                ->where('items.0.message', $message)
                ->missing('items.0.data'));
        $panelUrl = '/stores/'.$connection->store_id.'/orders/'.$order->id;
        $this->actingAs($connection->store->owner)->get($panelUrl)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('attempts.0.supplier_response.message', $message)
                ->where('attempts.0.supplier_response.parser_error', 'Belum ada pola/status yang cocok.')
                ->missing('attempts.0.request_snapshot'));
        $other = $this->shop();
        $this->actingAs($other->owner)->get($panelUrl)->assertForbidden();
        $success = $this->message($attempt->reference);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->getJson($url.'?'.http_build_query(['refid' => $attempt->reference, 'status' => '20', 'price' => '10000', 'message' => $success]))->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame($success, $attempt->fresh()->request_snapshot['supplier_response']['message']);
        $this->assertSame($success, OtomaxEvent::where('reference', $attempt->reference)->where('status', 'success')->firstOrFail()->data['message']);
        $this->assertSame($message, $event->fresh()->data['message']);
        $this->assertNull($attempt->fresh()->request_snapshot['supplier_response']['parser_error']);
        $this->assertStringNotContainsString($success, (string) DB::table('order_attempts')->where('id', $attempt->id)->value('request_snapshot'));
    }

    public function test_callback_event_list_keeps_legacy_messages_empty_and_isolates_store_data(): void
    {
        $connection = $this->connection();
        $foreign = $this->connection();
        OtomaxEvent::create(['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'operation' => 'callback', 'status' => 'success', 'reference' => 'LEGACY-REF', 'data' => ['parsed' => ['fields' => ['refid' => 'LEGACY-REF']], 'private' => 'DO-NOT-EXPOSE']]);
        OtomaxEvent::create(['store_id' => $foreign->store_id, 'connection_id' => $foreign->id, 'operation' => 'callback', 'status' => 'success', 'reference' => 'FOREIGN-REF', 'data' => ['message' => 'FOREIGN-MESSAGE']]);
        $this->actingAs($connection->store->owner)->get($this->base($connection->store).'?tab=events')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('items', 1)
                ->where('items.0.reference', 'LEGACY-REF')->where('items.0.message', null)->missing('items.0.data'))
            ->assertDontSee('DO-NOT-EXPOSE')->assertDontSee('FOREIGN-MESSAGE');
        $this->get($this->base($foreign->store).'?tab=events')->assertForbidden();
    }

    public function test_callback_token_ip_and_store_isolation(): void
    {
        $c = $this->connection();
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->getJson('/api/commerce/otomax/'.$c->id.'/wrong')->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])->getJson('/api/commerce/otomax/'.$c->id.'/'.str_repeat('a', 48))->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_sandbox_never_hits_supplier_and_tickets_do_not_duplicate(): void
    {
        $c = $this->connection();
        $s = $c->store;
        $s->update(['sandbox' => true]);
        $c->setRelation('store', $s);
        $this->assertSame('1000000', app(OtomaxClient::class)->send($c, 'balance')['fields']['saldo']);
        $this->actingAs($s->owner);
        $d = ['amount' => 50000, 'request_id' => (string) Str::uuid()];
        $this->postJson($this->base($s).'/connections/'.$c->id.'/ticket', $d)->assertRedirect();
        $this->postJson($this->base($s).'/connections/'.$c->id.'/ticket', $d)->assertRedirect();
        $this->assertSame(1, OtomaxTicket::count());
        Http::assertNothingSent();
    }

    public function test_xlsx_reader_preserves_values_without_calculating_formulas(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['Kode', 'Nama', 'Harga', 'Status'], ['001', 'Produk A', '8.700', 'Open'], ['002', 'Produk B', '=1+1', 'Open']]);
        $sheet->getCell('C2')->setValueExplicit('8.700', DataType::TYPE_STRING);
        $sheet->getCell('A2')->setValueExplicit('001', DataType::TYPE_STRING);
        $path = tempnam(sys_get_temp_dir(), 'oto');
        try {
            (new Xlsx($book))->save($path);
            $config = OtomaxImports::defaults();
            $config['header_row'] = 1;
            $config['grouped'] = false;
            $config['prefix_group'] = false;
            $config['columns']['status'] = 'D';
            $service = app(OtomaxImports::class);
            $read = $service->read($path, 'xlsx', $config);
            $r = $service->normalize($read['rows'], $config);
            $this->assertSame(1, $r['summary']['valid']);
            $this->assertSame('001', $r['rows'][0]['sku']);
            $this->assertSame(1, $r['summary']['rejected']);
        } finally {
            unlink($path);
            $book->disconnectWorksheets();
        }
    }

    public function test_captured_status_is_checked_before_a_rule_can_confirm_success(): void
    {
        $config = OtomaxParser::defaults();
        $config['rules'] = [['name' => 'Status teks', 'event' => 'callback', 'outcome' => 'success', 'active' => true, 'template' => '[refid] [status] SN:[sn]'], ['name' => 'Gagal teks', 'event' => 'callback', 'outcome' => 'failed', 'active' => true, 'template' => '[refid] [status] SN:[sn]']];
        $parser = app(OtomaxParser::class);
        $this->assertSame('success', $parser->parse($config, 'callback', 'REF SUKSES SN:123')['status']);
        $this->assertSame('failed', $parser->parse($config, 'callback', 'REF GAGAL SN:123')['status']);
        $this->assertNotNull($parser->parse($config, 'callback', 'REF DIBATALKAN SN:123')['error']);
    }

    public function test_callback_uses_original_profile_and_can_reverse_refund_to_negative_balance(): void
    {
        $c = $this->connection();
        $order = $this->order($c);
        Http::fake(['*' => Http::response('#12345 TEST.08123456789 akan diproses @16:46. Saldo 100.000 - 10.000 = 90.000')]);
        app(CustomerFulfillment::class)->run($order);
        $attempt = $order->attempts()->first();
        OtomaxProfile::find($c->response_profile_id)->update(['version' => 2, 'config' => [...OtomaxParser::defaults(), 'rules' => []]]);
        $url = '/api/commerce/otomax/'.$c->id.'/'.str_repeat('a', 48);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8']);
        $failed = ['refid' => $attempt->reference, 'status' => '40', 'price' => '10000', 'message' => 'Gagal terkonfirmasi'];
        $this->getJson($url.'?'.http_build_query($failed))->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
        $customer = $order->customer;
        $customer->update(['balance' => 1000]);
        $success = ['refid' => $attempt->reference, 'status' => '20', 'price' => '10000', 'message' => $this->message($attempt->reference)];
        $this->getJson($url.'?'.http_build_query($success))->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(-14000, (int) $customer->fresh()->balance);
        $this->getJson($url.'?'.http_build_query($success))->assertOk();
        $this->assertSame(-14000, (int) $customer->fresh()->balance);
        Http::assertSentCount(1);
    }

    public function test_previews_cannot_apply_after_connection_or_mode_changes(): void
    {
        $c = $this->connection();
        $s = $c->store;
        $config = OtomaxImports::defaults();
        $config['header_row'] = 1;
        $config['grouped'] = false;
        $config['columns']['status'] = 'D';
        $this->actingAs($s->owner);
        $r = $this->postJson($this->base($s).'/connections/'.$c->id.'/preview', ['file' => UploadedFile::fake()->createWithContent('harga.csv', "Kode;Nama;Harga;Status\nA;Produk;1000;Open\n"), 'config' => $config])->assertOk();
        $s->update(['sandbox' => true]);
        $this->postJson($this->base($s).'/imports/'.$r->json('id').'/confirm', ['accept_partial' => false, 'missing' => 'keep'])->assertRedirect();
        $this->assertSame('failed', OtomaxImport::find($r->json('id'))->status);
        $this->assertSame(0, SupplierProduct::where('connection_id', $c->id)->count());
        Http::assertNothingSent();
    }

    public function test_viewer_cannot_read_deposit_history_or_modify_profiles(): void
    {
        $c = $this->connection();
        $s = $c->store;
        $staff = StoreStaff::create(['store_id' => $s->id, 'name' => 'Viewer', 'username' => 'viewer', 'email' => 'viewer@example.test', 'password' => bcrypt('test-only'), 'active' => true, 'permissions' => ['products.view']]);
        $this->actingAs($staff, 'staff')->get($this->base($s).'?tab=tickets')->assertForbidden();
        $this->getJson($this->base($s).'/profiles/'.$c->response_profile_id)->assertForbidden();
        $this->postJson($this->base($s).'/profiles', ['type' => 'response', 'name' => 'Tidak boleh', 'config' => OtomaxParser::defaults()])->assertForbidden();
    }

    public function test_boundary_catchers_capture_supplier_id_sku_target_sn_cost_and_reference(): void
    {
        $parser = app(OtomaxParser::class);
        $config = [...OtomaxParser::defaults(), 'rules' => OtomaxParser::examples()];
        $parser->validate($config);
        $result = $parser->inspect($config, 'callback', $this->message('REF9'));
        $this->assertSame('success', $result['status']);
        $this->assertSame('12345', $result['fields']['id_transaksi']);
        $this->assertSame('TEST', $result['fields']['kode_produk']);
        $this->assertSame('08123456789', $result['fields']['tujuan']);
        $this->assertSame('001/hasil', $result['fields']['sn']);
        $this->assertSame('10.000', $result['fields']['harga']);
        $this->assertSame('REF9', $result['fields']['refid']);
        $this->assertTrue($result['checks'][0]['matched']);
        $this->assertSame('pending', $parser->parse($config, 'transaction', '#12345 TEST.08123456789 akan diproses @16:46. Saldo 100.000 - 10.000 = 90.000')['status']);
        $wrong = str_replace('SN/Ref: ', 'SN: ', $this->message('REF9'));
        $this->assertNotNull($parser->inspect($config, 'callback', $wrong)['error']);
    }

    public function test_named_regex_and_unicode_capture_positions_are_reported_without_losing_digits(): void
    {
        $parser = app(OtomaxParser::class);
        $rule = ['name' => 'RegEx sukses', 'event' => 'callback', 'outcome' => 'success', 'active' => true, 'matcher' => 'regex', 'regex' => 'SN: (?<sn>.+?) END ref=(?<refid>\S+)', 'case_sensitive' => false];
        $config = [...OtomaxParser::defaults(), 'rules' => [$rule]];
        $parser->validate($config);
        $text = 'Pembayaran 😀 SN: 001/hasil END ref=REF1';
        $result = $parser->inspect($config, 'callback', $text);
        $this->assertSame('001/hasil', $result['fields']['sn']);
        $this->assertSame('success', $result['status']);
        $span = $result['checks'][0]['captures'][0];
        $this->assertSame('001/hasil', mb_substr($text, $span['start'], $span['length']));
    }

    public function test_invalid_recursive_or_unknown_regex_and_duplicate_boundary_fields_are_rejected(): void
    {
        $parser = app(OtomaxParser::class);
        foreach (['(?<sn>', '(?<unknown>.+)', '(?<sn>(?R))', '(*LIMIT_MATCH=999999)(?<sn>.+)'] as $regex) {
            try {
                $parser->validate([...OtomaxParser::defaults(), 'rules' => [['name' => 'Tidak valid', 'event' => 'callback', 'outcome' => 'success', 'active' => true, 'matcher' => 'regex', 'regex' => $regex]]]);
                $this->fail('Unsafe or malformed regex was accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $rule = OtomaxParser::examples()[0];
        $rule['captures'][] = $rule['captures'][0];
        $this->expectException(ValidationException::class);
        $parser->validate([...OtomaxParser::defaults(), 'rules' => [$rule]]);
    }

    public function test_boundary_spaces_are_preserved_by_profile_save_and_the_separate_test_endpoint(): void
    {
        $connection = $this->connection();
        $store = $connection->store;
        $config = [...OtomaxParser::defaults(), 'rules' => OtomaxParser::examples()];
        $this->actingAs($store->owner);
        $this->patchJson($this->base($store).'/profiles/'.$connection->response_profile_id, ['type' => 'response', 'name' => 'Awalan akhiran', 'version' => 1, 'config' => $config])->assertRedirect();
        $saved = OtomaxProfile::find($connection->response_profile_id)->config;
        $this->assertSame(' - ', $saved['rules'][0]['captures'][5]['prefix']);
        $this->assertSame(' = ', $saved['rules'][0]['captures'][5]['suffix']);
        $this->assertSame(' SUKSES.', $saved['rules'][0]['keyword']);
        $this->get($this->base($store).'/tester')->assertOk();
        $this->postJson($this->base($store).'/test', ['config' => $saved, 'event' => 'callback', 'text' => $this->message('REF7')])->assertOk()->assertJsonPath('status', 'success')->assertJsonPath('fields.harga', '10.000')->assertJsonPath('checks.0.matched', true);
        Http::assertNothingSent();
    }

    public function test_tester_is_scoped_to_store_and_response_permission(): void
    {
        $connection = $this->connection();
        $store = $connection->store;
        $outsider = $this->shop();
        $this->actingAs($outsider->owner)->get($this->base($store).'/tester')->assertForbidden();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Pembaca', 'username' => 'pembaca', 'email' => 'pembaca@example.test', 'password' => bcrypt('test-only'), 'active' => true, 'permissions' => ['products.view']]);
        $this->actingAs($staff, 'staff')->get($this->base($store).'/tester')->assertForbidden();
        $staff->update(['permissions' => ['suppliers.responses']]);
        $this->actingAs($staff->fresh(), 'staff')->get($this->base($store).'/tester')->assertOk();
        Http::assertNothingSent();
    }

    public function test_priority_does_not_allow_conflicting_catchers_to_confirm_financial_status(): void
    {
        $rule = OtomaxParser::examples()[0];
        $second = [...$rule, 'name' => 'Konflik', 'outcome' => 'failed', 'priority' => 1];
        $config = [...OtomaxParser::defaults(), 'rules' => [$second, $rule]];
        $result = app(OtomaxParser::class)->inspect($config, 'callback', $this->message('REF1'));
        $this->assertSame('Sukses OtomaX', $result['checks'][0]['name']);
        $this->assertSame('pending', $result['status']);
        $this->assertNotNull($result['error']);
    }

    public function test_regex_processing_limit_and_legacy_literal_rules_have_safe_results(): void
    {
        $parser = app(OtomaxParser::class);
        $rule = ['name' => 'Batas RegEx', 'event' => 'callback', 'outcome' => 'success', 'active' => true, 'matcher' => 'regex', 'regex' => '^(?<sn>(a+)+)b$'];
        $config = [...OtomaxParser::defaults(), 'rules' => [$rule]];
        $parser->validate($config);
        $result = $parser->parse($config, 'callback', str_repeat('a', 4000));
        $this->assertSame('pending', $result['status']);
        $this->assertNotNull($result['error']);
        $empty = [...$rule, 'regex' => '^(?<sn>.*)$'];
        $this->assertSame('pending', $parser->parse([...OtomaxParser::defaults(), 'rules' => [$empty]], 'callback', '')['status']);
        $legacy = [...OtomaxParser::defaults(), 'rules' => [['name' => 'Gagal jelas', 'event' => 'transaction', 'outcome' => 'failed', 'active' => true, 'template' => 'GAGAL']]];
        $this->assertSame('failed', $parser->parse($legacy, 'transaction', 'GAGAL')['status']);
    }

    public function test_real_workreport_sample_when_explicitly_provided(): void
    {
        $path = getenv('OTOMAX_SAMPLE_XLSX');
        if (! $path || ! is_file($path)) {
            $this->markTestSkipped('Set OTOMAX_SAMPLE_XLSX to inspect a supplier export without modifying it.');
        }
        $service = app(OtomaxImports::class);
        $result = $service->normalize($service->read($path, 'xlsx', OtomaxImports::defaults())['rows'], OtomaxImports::defaults());
        $this->assertGreaterThan(5000, $result['summary']['valid']);
        $this->assertGreaterThan(0, $result['summary']['rejected']);
        $this->assertSame(0, $result['summary']['duplicates']);
        foreach ($result['rows'] as $row) {
            $this->assertGreaterThan(0, $row['cost']);
        }
        $connection = $this->connection();
        $this->actingAs($connection->store->owner);
        $preview = $this->postJson($this->base($connection->store).'/connections/'.$connection->id.'/preview', ['file' => UploadedFile::fake()->createWithContent('webreport.xlsx', file_get_contents($path)), 'config' => OtomaxImports::defaults()])->assertOk();
        $record = OtomaxImport::findOrFail($preview->json('id'));
        $this->assertSame($result['summary']['valid'], $preview->json('summary.valid'));
        $this->assertLessThan(32768, strlen($record->getRawOriginal('payload')));
        $this->assertSame(count($result['rows']), count($service->previewRows($record, $record->payload)));

    }
}
