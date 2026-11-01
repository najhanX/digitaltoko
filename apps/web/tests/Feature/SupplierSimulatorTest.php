<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerOrder;
use App\Models\OtomaxProfile;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\OtomaxClient;
use App\Services\OtomaxParser;
use App\Services\OtomaxWebCatalog;
use App\Services\PostpaidBilling;
use App\Services\StoreCatalog;
use App\Services\SupplierSimulator;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['suppliers.simulator.enabled' => true, 'suppliers.cache_store' => 'array', 'app.url' => 'http://localhost:8012', 'platform.domain' => 'localhost', 'inertia.ssr.enabled' => false]);
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $store = Store::create(['owner_id' => $owner->id, 'slug' => 'lab-minimum', 'name' => 'Lab', 'sandbox' => true, 'category' => 'web', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addMonth()]);
        $profileConfig = OtomaxParser::defaults();
        $profileConfig['rules'][] = ['name' => 'Gagal ringkas simulator', 'event' => 'callback', 'active' => true, 'template' => 'GAGAL'];
        $profileConfig['rules'][] = ['name' => 'Gagal awal simulator', 'event' => 'transaction', 'active' => true, 'template' => 'GAGAL'];
        $profileConfig['rules'][] = [...$profileConfig['rules'][0], 'event' => 'transaction'];
        $profile = OtomaxProfile::create(['store_id' => $store->id, 'type' => 'response', 'name' => 'Lab', 'config' => $profileConfig]);
        $connection = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => 'lab-a', 'name' => 'Simulator A', 'enabled' => true, 'synced_at' => now(), 'response_profile_id' => $profile->id,
            'credentials' => ['member_id' => 'SIM001', 'pin' => '1234', 'password' => 'simulator-only', 'callback_token' => str_repeat('x', 48)],
            'settings' => ['base_url' => 'http://127.0.0.1:4101', 'transaction_path' => '/trx', 'balance_path' => '/balance', 'status_path' => '/status', 'callback_ips' => ['127.0.0.1'], 'catalog_hours' => 24]]);
        $item = SupplierProduct::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'sku' => 'XLD10', 'name' => 'XL', 'kind' => 'ppob', 'mode' => 'prepaid', 'service' => 'Pulsa', 'category' => 'XL', 'cost' => 10000, 'available' => true, 'observed_at' => now()]);
        $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'LAB-XLD10', 'name' => 'XL', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'XL', 'sale_price' => 10500, 'active' => true]);
        $mapping = ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'otomax', 'source_key' => 'item:'.$item->id, 'supplier_product_id' => $item->id, 'active' => true]);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Buyer Lab', 'email' => 'buyer@example.test', 'phone' => '6281200000001', 'phone_verified_at' => now(), 'balance' => 250000, 'sandbox_balance' => 200000]);

        return compact('store', 'connection', 'item', 'product', 'customer', 'mapping');
    }

    private function order(array $f): CustomerOrder
    {
        return app(CustomerCommerce::class)->begin($f['store'], ['purpose' => 'product', 'product_id' => $f['product']->id, 'payment' => 'wallet', 'phone' => $f['customer']->phone, 'destination' => '081200000001', 'idempotency_key' => 'supplier-lab-test'], $f['customer']);
    }

    public function test_loopback_exception_is_opt_in_and_only_fixed_endpoints(): void
    {
        $client = app(OtomaxClient::class);
        $this->assertSame(4101, $client->endpoint(['base_url' => 'http://127.0.0.1:4101'])['port']);
        $this->assertSame(4102, app(OtomaxWebCatalog::class)->target('http://127.0.0.1:4102/harga.js.php')['port']);
        foreach (['http://127.0.0.1:4000', 'http://localhost:4101', 'http://127.0.0.1:4101/private'] as $url) {
            try {
                $client->endpoint(['base_url' => $url]);
                $this->fail('Unlisted endpoint allowed');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        config(['suppliers.simulator.enabled' => false]);
        $this->expectException(ValidationException::class);
        $client->endpoint(['base_url' => 'http://127.0.0.1:4101']);
    }

    public function test_other_database_and_production_environment_cannot_enable_lab(): void
    {
        config(['database.default' => 'mysql']);
        try {
            app(SupplierSimulator::class)->assertIsolated();
            $this->fail('MySQL was permitted');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('terpisah', $error->getMessage());
        }
        config(['database.default' => 'sqlite']);
        $this->app['env'] = 'production';
        $this->assertFalse(app(SupplierSimulator::class)->enabled());
        $this->expectException(ValidationException::class);
        app(OtomaxClient::class)->endpoint(['base_url' => 'http://127.0.0.1:4101']);
    }

    public function test_live_transport_requires_sandbox_allowed_store_and_synthetic_credentials(): void
    {
        $f = $this->fixture();
        $this->assertTrue(app(SupplierSimulator::class)->connection($f['connection']));
        $f['store']->update(['sandbox' => false]);
        $this->expectException(ValidationException::class);
        app(SupplierSimulator::class)->connection($f['connection']->fresh());
    }

    public function test_foreign_store_and_real_credentials_are_rejected(): void
    {
        $f = $this->fixture();
        $f['store']->update(['slug' => 'other-store']);
        try {
            app(SupplierSimulator::class)->connection($f['connection']->fresh());
            $this->fail('Other store permitted');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $f['store']->update(['slug' => 'lab-minimum']);
        $f['connection']->update(['credentials' => [...$f['connection']->credentials, 'password' => 'REAL-KEY']]);
        $this->expectException(ValidationException::class);
        app(SupplierSimulator::class)->connection($f['connection']->fresh());
    }

    public function test_sandbox_order_uses_signed_http_and_real_callback_without_touching_real_wallet(): void
    {
        $f = $this->fixture();
        Http::fake(['127.0.0.1:4101/*' => Http::response('#123 XLD10.081200000001 akan diproses @10:00. Saldo 1.000.000 - 10.000 = 990.000')]);
        $order = $this->order($f);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('pending', $order->fresh()->status);
        Http::assertSent(function ($request) use ($f): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $params);

            return ($params['cb_url'] ?? '') === 'http://localhost:8012/api/commerce/otomax/'.$f['connection']->id.'/'.str_repeat('x', 48)
                && $params['sign'] === OtomaxClient::sign('SIM001', '1234', 'simulator-only', 'transaction', $params);
        });
        $attempt = $order->attempts()->firstOrFail();
        $message = '#123 XLD10.081200000001 SUKSES. SN/Ref: SIM123. Saldo 1.000.000 - 10.000 = 990.000 @30/10 10:00:00 R#'.$attempt->reference;
        $payload = ['refid' => $attempt->reference, 'status' => '20', 'price' => '10000', 'message' => $message];
        $path = '/api/commerce/otomax/'.$f['connection']->id.'/'.str_repeat('x', 48);
        $this->getJson('http://localhost'.$path.'?'.http_build_query($payload))->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->getJson('http://localhost'.$path.'?'.http_build_query($payload))->assertOk();
        $this->assertSame(189500, $f['customer']->fresh()->sandbox_balance);
        $this->assertSame(250000, $f['customer']->fresh()->balance);
        $this->assertSame(1, CustomerLedger::where('customer_id', $f['customer']->id)->where('reason', 'purchase')->count());
    }

    public function test_failed_simulated_http_order_refunds_only_sandbox_balance_once(): void
    {
        $f = $this->fixture();
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $p);

            return Http::response(['status' => '40', 'refid' => $p['refID'], 'price' => 10000, 'message' => 'GAGAL']);
        });
        $order = $this->order($f);
        app(CustomerFulfillment::class)->run($order);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(200000, $f['customer']->fresh()->sandbox_balance);
        $this->assertSame(250000, $f['customer']->fresh()->balance);
        $this->assertSame(1, CustomerLedger::where('customer_id', $f['customer']->id)->where('reason', 'refund')->count());
    }

    public function test_positive_otomax_postpaid_price_is_retained_as_adjustment_without_billing_metadata(): void
    {
        $f = $this->fixture();
        $f['item']->update(['cost' => 200, 'kind' => 'ppob', 'billing' => null]);
        $check = SupplierProduct::create([...$f['item']->only(['store_id', 'connection_id', 'mode', 'service', 'category']), 'sku' => 'CEKPLN20', 'name' => 'Cek', 'kind' => 'postpaid', 'cost' => 0, 'billing' => ['adjustment' => 0], 'available' => true, 'observed_at' => now()]);
        $f['product']->update(['kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'auto_update' => true]);
        $f['mapping']->update(['postpaid' => ['inquiry_id' => $check->id, 'admin' => 2000]]);
        PricingRule::create(['store_id' => $f['store']->id, 'scope' => 'service', 'key' => 'pascabayar', 'markup' => '1000', 'auto_update' => true, 'pricing_basis' => 'highest']);
        $quote = app(StoreCatalog::class)->present(app(StoreCatalog::class)->query($f['store'], false)->findOrFail($f['product']->id), $f['store'], true);
        $this->assertSame(3200, $quote['price']);
        $this->assertSame(200, $quote['candidates'][0]['commission']);
    }

    public function test_sandbox_without_local_adapter_keeps_original_no_network_behavior(): void
    {
        $f = $this->fixture();
        config(['suppliers.simulator.enabled' => false]);
        $result = app(OtomaxClient::class)->send($f['connection'], 'transaction', ['product' => 'XLD10', 'dest' => '081200000001', 'refID' => 'NORMAL-SANDBOX', 'cost' => 10000]);
        $this->assertSame('success', $result['status']);
        Http::assertNothingSent();
    }

    public function test_lab_network_guard_rejects_external_supplier_and_unlisted_local_service(): void
    {
        $guard = app(SupplierSimulator::class);
        $allowed = new Request('GET', 'http://127.0.0.1:4102/trx');
        $this->assertSame($allowed, $guard->guardRequest($allowed));
        foreach (['https://api.digiflazz.com/v1/transaction', 'http://127.0.0.1:4000', 'http://localhost:4102'] as $url) {
            try {
                $guard->guardRequest(new Request('GET', $url));
                $this->fail('External request allowed');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('memblokir', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_actual_supplier_cost_above_locked_invoice_is_reviewed_and_does_not_raise_customer_price(): void
    {
        $f = $this->fixture();
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $p);

            return Http::response(['status' => '20', 'refid' => $p['refID'], 'price' => 11900, 'message' => '#1 XLD10.081200000001 SUKSES. SN/Ref: SIM1. Saldo 1.000.000 - 11.900 = 988.100 @30/10 10:00:00 R#'.$p['refID']]);
        });
        $order = $this->order($f);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('review', $order->fresh()->status);
        $this->assertSame(10500, $order->fresh()->amount);
        $this->assertSame(189500, $f['customer']->fresh()->sandbox_balance);
        Http::assertSentCount(1);
    }

    public function test_failed_callback_continues_unsent_fallback_after_releasing_sync_lab_lock(): void
    {
        $f = $this->fixture();
        $b = $f['connection']->replicate();
        $b->slot = 'lab-b';
        $b->settings = [...$b->settings, 'base_url' => 'http://127.0.0.1:4102'];
        $b->credentials = [...$b->credentials, 'member_id' => 'SIM002'];
        $b->save();
        $item = $f['item']->replicate();
        $item->connection_id = $b->id;
        $item->cost = 10400;
        $item->save();
        ProductMapping::create(['store_id' => $f['store']->id, 'product_id' => $f['product']->id, 'source' => 'otomax', 'source_key' => 'item:'.$item->id, 'supplier_product_id' => $item->id, 'active' => true]);
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $p);
            if (str_contains($request->url(), ':4101/')) {
                return Http::response('#1 XLD10.081200000001 akan diproses @10:00. Saldo 1.000.000 - 10.000 = 990.000');
            }

            return Http::response(['status' => '20', 'price' => 10400, 'refid' => $p['refID'], 'message' => '#2 XLD10.081200000001 SUKSES. SN/Ref: SIM2. Saldo 1.000.000 - 10.400 = 989.600 @30/10 10:00:00 R#'.$p['refID']]);
        });
        $order = $this->order($f);
        app(CustomerFulfillment::class)->run($order);
        $attempt = $order->attempts()->where('position', 1)->firstOrFail();
        $path = 'http://localhost/api/commerce/otomax/'.$f['connection']->id.'/'.str_repeat('x', 48);
        $this->getJson($path.'?'.http_build_query(['refid' => $attempt->reference, 'status' => '40', 'price' => '10000', 'message' => 'GAGAL']))->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('success', $order->attempts()->where('position', 2)->firstOrFail()->status);
        $this->assertSame(189500, $f['customer']->fresh()->sandbox_balance);
        Http::assertSentCount(2);
    }

    private function hybridFixture(): array
    {
        $f = $this->fixture();
        $f['item']->update(['cost' => 10100]);
        $d = SupplierConnection::create(['store_id' => $f['store']->id, 'kind' => 'digiflazz', 'slot' => 'lab-digiflazz', 'enabled' => true, 'synced_at' => now(),
            'credentials' => ['username' => 'LAB-DEV', 'development_key' => 'ONLY-DEV', 'webhook_secret' => str_repeat('d', 32)]]);
        $item = SupplierProduct::create(['store_id' => $f['store']->id, 'connection_id' => $d->id, 'sku' => 'xld10', 'name' => 'XL dev', 'kind' => 'ppob', 'mode' => 'prepaid', 'service' => 'Pulsa', 'category' => 'XL', 'cost' => 10000, 'available' => true, 'observed_at' => now()]);
        ProductMapping::create(['store_id' => $f['store']->id, 'product_id' => $f['product']->id, 'source' => 'digiflazz', 'source_key' => 'item:'.$item->id, 'supplier_product_id' => $item->id, 'active' => true]);
        $order = app(CustomerCommerce::class)->begin($f['store'], ['purpose' => 'product', 'product_id' => $f['product']->id, 'payment' => 'wallet', 'phone' => $f['customer']->phone, 'destination' => '087800001234', 'idempotency_key' => 'hybrid-test-reference'], $f['customer']);

        return [...$f, 'digiflazz' => $d, 'order' => $order];
    }

    public function test_hybrid_egress_requires_opt_in_signed_development_request_and_exact_order_fixture(): void
    {
        $f = $this->hybridFixture();
        $attempt = $f['order']->attempts()->where('source', 'digiflazz')->firstOrFail();
        $data = ['username' => 'LAB-DEV', 'testing' => true, 'buyer_sku_code' => 'xld10', 'customer_no' => '087800001234', 'ref_id' => $attempt->reference, 'sign' => md5('LAB-DEVONLY-DEV'.$attempt->reference)];
        config(['suppliers.simulator.digiflazz_development' => true, 'suppliers.simulator.digiflazz_callback_url' => 'https://lab.example.test/api/commerce/digiflazz']);
        $data['cb_url'] = 'https://lab.example.test/api/commerce/digiflazz';
        $guard = app(SupplierSimulator::class);
        $request = new Request('POST', 'https://api.digiflazz.com/v1/transaction', [], json_encode($data));
        $this->assertSame($request, $guard->guardRequest($request));
        foreach ([['testing' => false], ['buyer_sku_code' => 'other'], ['customer_no' => '08123456789'], ['sign' => 'production'], ['commands' => 'pay-pasca'], ['ref_id' => 'foreign'], ['cb_url' => 'https://other.example.test']] as $patch) {
            try {
                $guard->guardRequest(new Request('POST', 'https://api.digiflazz.com/v1/transaction', [], json_encode([...$data, ...$patch])));
                $this->fail('Unsafe hybrid request accepted');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('memblokir', $error->getMessage());
            }
        }
        config(['suppliers.simulator.digiflazz_development' => false]);
        $this->expectException(\RuntimeException::class);
        $guard->guardRequest($request);
    }

    public function test_digiflazz_pending_does_not_failover_until_signed_failed_callback_releases_lab_lock(): void
    {
        $f = $this->hybridFixture();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'digiflazz.com')) {
                return Http::response(['data' => ['ref_id' => $request['ref_id'], 'buyer_sku_code' => 'xld10', 'customer_no' => $request['customer_no'], 'status' => 'Pending', 'rc' => '03', 'price' => 10000]]);
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $p);

            return Http::response(['status' => '20', 'price' => 10100, 'refid' => $p['refID'], 'message' => '#2 XLD10.087800001234 SUKSES. SN/Ref: SIM2. Saldo 1.000.000 - 10.100 = 989.900 @30/10 10:00:00 R#'.$p['refID']]);
        });
        app(CustomerFulfillment::class)->run($f['order']);
        $this->assertSame('pending', $f['order']->fresh()->status);
        Http::assertSentCount(1);
        $attempt = $f['order']->attempts()->where('source', 'digiflazz')->firstOrFail();
        $payload = ['data' => ['ref_id' => $attempt->reference, 'buyer_sku_code' => 'xld10', 'customer_no' => '087800001234', 'status' => 'Gagal', 'rc' => '02', 'price' => 10000]];
        $body = json_encode($payload);
        $this->call('POST', 'http://localhost/api/commerce/digiflazz', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => 'sha1='.hash_hmac('sha1', $body, str_repeat('d', 32))], $body)->assertOk();
        $this->assertSame('success', $f['order']->fresh()->status);
        $this->assertSame(10500, $f['order']->fresh()->amount);
        Http::assertSentCount(2);
    }

    public function test_hybrid_cli_requires_installed_product_before_resetting_supplier_state(): void
    {
        config(['suppliers.simulator.digiflazz_development' => true]);
        $this->artisan('supplier-lab', ['action' => 'exercise', '--store' => 'maximum', '--kind' => 'hybrid', '--destination' => '087800001232'])
            ->expectsOutput('Jalankan supplier-lab digiflazz dahulu.')
            ->assertExitCode(2);
        Http::assertNothingSent();
    }

    public function test_hybrid_no_reset_rejects_changing_shared_supplier_configuration(): void
    {
        $this->artisan('supplier-lab', ['action' => 'exercise', '--store' => 'maximum', '--kind' => 'hybrid', '--destination' => '087800001232', '--no-reset' => true, '--fallback' => 'a'])
            ->expectsOutput('--no-reset hanya untuk hybrid dengan konfigurasi supplier saat ini; jangan gabungkan dengan --fallback/preset lain.')
            ->assertExitCode(2);
        Http::assertNothingSent();
    }

    public function test_postpaid_inquiry_egress_is_scoped_and_cannot_authorize_payment_or_escape_scope(): void
    {
        $f = $this->hybridFixture();
        config(['suppliers.simulator.digiflazz_development' => true]);
        $guard = app(SupplierSimulator::class);
        $payload = ['commands' => 'inq-pasca', 'testing' => true, 'username' => 'LAB-DEV', 'buyer_sku_code' => 'pln', 'customer_no' => '530000000001', 'ref_id' => 'DT-SCOPED-INQ', 'sign' => md5('LAB-DEVONLY-DEV'.'DT-SCOPED-INQ')];
        $request = new Request('POST', 'https://api.digiflazz.com/v1/transaction', [], json_encode($payload));
        $run = function () use ($guard, $request, $payload) {
            $this->assertSame($request, $guard->guardRequest($request));
            try {
                $guard->guardRequest(new Request('POST', 'https://api.digiflazz.com/v1/transaction', [], json_encode([...$payload, 'commands' => 'pay-pasca'])));
                $this->fail('Inquiry scope authorized payment');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }

            return new \Illuminate\Http\Client\Response(new Response(200, [], '{"data":{"rc":"00"}}'));
        };
        $guard->inquiryRequest($f['store'], $f['digiflazz'], 'DT-SCOPED-INQ', '530000000001', 'pln', $run);
        $this->expectException(\RuntimeException::class);
        $guard->guardRequest($request);
    }

    public function test_official_nested_postpaid_periods_are_preserved_for_fallback_matching(): void
    {
        $billing = app(PostpaidBilling::class);
        $this->assertSame('201901', $billing->digiflazzPeriod(['desc' => ['detail' => [['periode' => '201901']]]]));
        $this->assertSame('201901,201902', $billing->digiflazzPeriod(['desc' => ['detail' => [['periode' => '201901'], ['periode' => '201902']]]]));
        $this->assertSame('202610', $billing->digiflazzPeriod(['periode' => '202610', 'desc' => ['detail' => [['periode' => '201901']]]]));
        $this->assertSame('', $billing->digiflazzPeriod(['desc' => ['detail' => [null, ['periode' => []]]]]));
    }
}
