<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerOrder;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerInquiry;
use App\Models\CustomerLedger;
use App\Models\CustomerOrder;
use App\Models\MaintenanceWindow;
use App\Models\OtomaxEvent;
use App\Models\OtomaxProfile;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerInquiryService;
use App\Services\CustomerNoticeText;
use App\Services\OtomaxInquiries;
use App\Services\OtomaxParser;
use App\Services\OtomaxWebCatalog;
use App\Services\PostpaidBilling;
use App\Services\PostpaidFailover;
use App\Services\ProductPricing;
use App\Services\StoreCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PostpaidCommerceTest extends TestCase
{
    use RefreshDatabase;

    private string $responseMode = 'success';

    private string $paymentStatus = 'success';

    private string $digiPaymentStatus = 'success';

    private string $digiName = 'Pelanggan Digiflazz';

    private string $digiPeriod = '202610';

    private array $digiDetails = [];

    private string $otomaxPeriod = '';

    private int $bill = 100000;

    private int $admin = 3000;

    private array $failedSkus = [];

    private int $digiCommission = 500;

    private int $otomaxAdjustment = -500;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false, 'suppliers.cache_store' => 'array']);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'api.digiflazz.com')) {
                $payment = $request['commands'] === 'pay-pasca';
                $failed = $payment && $this->digiPaymentStatus === 'failed';
                $pending = $payment && $this->digiPaymentStatus === 'pending';

                return Http::response(['data' => ['status' => $failed ? 'Gagal' : ($pending ? 'Pending' : 'Sukses'), 'rc' => $failed ? '44' : ($pending ? '03' : '00'), 'ref_id' => $request['ref_id'], 'customer_no' => $request['customer_no'], 'buyer_sku_code' => $request['buyer_sku_code'],
                    'price' => $this->bill + $this->admin - $this->digiCommission, 'selling_price' => $this->bill + $this->admin, 'admin' => $this->admin, 'customer_name' => $this->digiName, 'periode' => $this->digiPeriod, 'desc' => ['detail' => $this->digiDetails], 'sn' => 'DG-SUCCESS']]);
            }
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $this->requests[] = $query;
            $sku = $query['product'] ?? '';
            $dest = $query['dest'] ?? '';
            $ref = $query['refID'] ?? '';
            if (str_starts_with($sku, 'CEK')) {
                if ($this->responseMode === 'timeout') {
                    throw new ConnectionException('PRIVATE-TRANSPORT-ERROR');
                }
                if ($this->responseMode === 'failed') {
                    return Http::response('Cek PLN Admin 2.000 '.$sku.'.'.$dest.' GAGAL. Nomor tujuan salah. Sal 941 @09:39 R#'.$ref);
                }
                if ($this->responseMode === 'pending') {
                    return Http::response('#1 '.$sku.'.'.$dest.' akan diproses @12:00. Saldo 200.000 - 0 = 200.000');
                }
                $sn = $this->responseMode === 'malformed' ? 'TIDAK ADA TAGIHAN' : 'NAMA:PELANGGAN/ALAMAT:TEST/TAG:'.$this->bill.'/ADM::'.$this->admin.'/TTAG:'.($this->bill + $this->admin).($this->otomaxPeriod === '' ? '' : '/PTAG:'.$this->otomaxPeriod).' RC:00 INQUIRY';

                return Http::response(['status' => '20', 'price' => 0, 'refid' => $ref, 'message' => '#1 '.$sku.'.'.$dest.' SUKSES. SN/Ref: '.$sn.'. Saldo 200.000 - 0 = 200.000 @29/10 12:00:00 R#'.$ref]);
            }

            if (in_array($sku, $this->failedSkus, true)) {
                return Http::response(['status' => '40', 'price' => 102500, 'refid' => $ref, 'message' => $sku.'.'.$dest.' GAGAL. R#'.$ref]);
            }
            if ($this->paymentStatus !== 'success') {
                return Http::response(['status' => $this->paymentStatus === 'failed' ? '40' : '1', 'price' => 103000 + $this->otomaxAdjustment, 'refid' => $ref, 'message' => $sku.'.'.$dest.' '.($this->paymentStatus === 'failed' ? 'GAGAL' : 'PENDING').'. R#'.$ref]);
            }

            return Http::response(['status' => '20', 'price' => $this->bill + $this->admin + $this->otomaxAdjustment, 'refid' => $ref, 'message' => '#2 '.$sku.'.'.$dest.' SUKSES. SN/Ref: NAMA:PELANGGAN/TAG:'.$this->bill.'/ADMIN:'.$this->admin.'/TTAG:'.($this->bill + $this->admin).'. Saldo 200.000 - '.number_format($this->bill + $this->admin + $this->otomaxAdjustment, 0, ',', '.').' = '.number_format(200000 - ($this->bill + $this->admin + $this->otomaxAdjustment), 0, ',', '.').' @29/10 12:00:00 R#'.$ref]);
        });
    }

    private function fixture(): array
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.fake()->unique()->numerify('########')]);
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Toko Pasca', 'slug' => 'pasca-'.Str::lower(Str::random(8)), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addMonth()]);
        $config = OtomaxParser::defaults();
        foreach (['transaction', 'callback', 'status'] as $event) {
            $config['rules'][] = ['name' => 'Status bayar ringkas', 'event' => $event, 'active' => true, 'template' => '[kode_produk].[tujuan] [status]. R#[refid]'];
        }
        $profile = OtomaxProfile::create(['store_id' => $store->id, 'type' => 'response', 'name' => 'Pasca', 'config' => $config]);
        $connection = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => (string) Str::uuid(), 'name' => 'Supplier Pasca', 'enabled' => true, 'synced_at' => now(), 'response_profile_id' => $profile->id,
            'credentials' => ['member_id' => 'DEMO', 'pin' => '1234', 'password' => 'TEST', 'callback_token' => str_repeat('p', 48)],
            'settings' => ['base_url' => 'http://8.8.8.8:6969', 'transaction_path' => '/trx', 'balance_path' => '/balance', 'status_path' => '', 'callback_ips' => ['127.0.0.1'], 'catalog_hours' => 24]]);
        $base = ['store_id' => $store->id, 'connection_id' => $connection->id, 'kind' => 'postpaid', 'mode' => 'prepaid', 'cost' => 0, 'available' => true, 'observed_at' => now(), 'service' => 'Pascabayar', 'category' => 'PLN'];
        $check = SupplierProduct::create([...$base, 'sku' => 'CEKPLN30', 'name' => 'Cek PLN', 'billing' => ['adjustment' => 0]]);
        $pay = SupplierProduct::create([...$base, 'sku' => 'BYRPLN30', 'name' => 'Bayar PLN', 'billing' => ['adjustment' => -500]]);
        $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'PLN30', 'name' => 'PLN Pasca', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'sale_price' => 4000, 'active' => true]);
        $mapping = ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'otomax', 'source_key' => 'item:'.$pay->id, 'supplier_product_id' => $pay->id, 'active' => true, 'postpaid' => ['inquiry_id' => $check->id, 'admin' => 3000]]);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Pembeli', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'balance' => 200000]);

        return compact('owner', 'store', 'connection', 'check', 'pay', 'product', 'mapping', 'customer');
    }

    private function quote(array $f, string $key = 'check-key'): CustomerInquiry
    {
        return app(CustomerInquiryService::class)->quote($f['store'], $f['product']->id, '12345678', $f['customer']->phone, $key);
    }

    private function order(array $f, CustomerInquiry $inquiry): CustomerOrder
    {
        return app(CustomerCommerce::class)->begin($f['store'], ['purpose' => 'product', 'product_id' => $f['product']->id, 'phone' => $f['customer']->phone, 'destination' => '12345678', 'payment' => 'wallet', 'inquiry_id' => $inquiry->id, 'expected_price' => $inquiry->amount, 'idempotency_key' => (string) Str::uuid()], $f['customer']);
    }

    public function test_quote_preserves_commission_and_payment_uses_its_own_sku_and_reference(): void
    {
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $this->assertSame('ready', $inquiry->status);
        $this->assertSame(104000, $inquiry->amount);
        $this->assertSame(102500, $inquiry->cost);
        $this->assertSame(500, $inquiry->data['commission']);
        $this->assertSame(1500, $inquiry->data['markup']);
        $this->assertSame('CEKPLN30', $this->requests[0]['product']);
        $order = $this->order($f, $inquiry);
        $this->assertNotSame($inquiry->reference, $order->attempts()->first()->reference);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('BYRPLN30', $this->requests[1]['product']);
        $this->assertSame(96000, $f['customer']->fresh()->balance);
        $this->assertSame(-500, $f['pay']->fresh()->billing['adjustment']);
        $this->assertSame(0, $f['pay']->fresh()->cost);
        $this->assertSame(102500, $order->attempts()->first()->cost);
    }

    public function test_pending_check_is_not_sent_again_and_callback_cannot_pay_the_order(): void
    {
        $this->responseMode = 'pending';
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $this->assertSame('pending', $inquiry->status);
        $again = $this->quote($f, 'another-message');
        $this->assertSame($inquiry->id, $again->id);
        Http::assertSentCount(1);
        $path = '/api/commerce/otomax/'.$f['connection']->id.'/'.str_repeat('p', 48);
        $body = ['status' => '20', 'price' => 0, 'refid' => $inquiry->reference, 'message' => '#3 CEKPLN30.12345678 SUKSES. SN/Ref: NAMA:PELANGGAN/TAG:100000/ADM::3000/TTAG:103000 RC:00 INQUIRY. Saldo 200.000 - 0 = 200.000 @29/10 12:00:00 R#'];
        $this->postJson($path, $body)->assertOk();
        $this->assertSame('ready', $inquiry->fresh()->status);
        $this->assertSame(104000, $inquiry->fresh()->amount);
        $this->postJson($path, $body)->assertOk();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertSame(200000, $f['customer']->fresh()->balance);
        Http::assertSentCount(1);
    }

    public function test_pending_or_incomplete_check_cannot_charge_customer(): void
    {
        $this->responseMode = 'malformed';
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $this->assertSame('pending', $inquiry->status);
        try {
            $this->order($f, $inquiry);
            $this->fail('Pending quote cannot be paid');
        } catch (ValidationException) {
        }
        $this->assertSame(200000, $f['customer']->fresh()->balance);
        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_wrong_check_sku_callback_is_rejected_without_readiness(): void
    {
        $this->responseMode = 'pending';
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $body = ['status' => '20', 'price' => 0, 'refid' => $inquiry->reference, 'message' => '#1 BYRPLN30.12345678 SUKSES. SN/Ref: NAMA:X/TAG:100000/ADMIN:3000/TTAG:103000. Saldo 200.000 - 0 = 200.000 @29/10 12:00:00 R#'.$inquiry->reference];
        $this->postJson('/api/commerce/otomax/'.$f['connection']->id.'/'.str_repeat('p', 48), $body)->assertUnprocessable();
        $this->assertSame('pending', $inquiry->fresh()->status);
    }

    public function test_unconfigured_pair_is_not_available_to_buyers(): void
    {
        $f = $this->fixture();
        $f['mapping']->update(['postpaid' => null]);
        $quote = app(StoreCatalog::class)->present(app(StoreCatalog::class)->query($f['store'], false)->findOrFail($f['product']->id), $f['store']);
        $this->assertFalse($quote['available']);
        Http::assertNothingSent();
    }

    public function test_supplied_pdam_check_format_and_malformed_bill_validation(): void
    {
        $values = app(PostpaidBilling::class)->otomax(['sn' => 'NAMA:2A2-PELANGGAN/PTAG:202609/JBLN:1/SM:00000000-00000020/TAG:87684/ADM::2500/TTAG:90184 RC:00 INQUIRY'], -500, 1000);
        $this->assertSame(90684, $values['amount']);
        $this->assertSame(89684, $values['cost']);
        $this->assertSame('202609', $values['period']);
        $this->expectException(ValidationException::class);
        app(PostpaidBilling::class)->otomax(['sn' => 'NAMA:X/TAG:100000/ADMIN:3000/TTAG:99999'], -500, 1000);
    }

    public function test_web_catalog_keeps_signed_commission_and_free_check_without_turning_them_into_prepaid_prices(): void
    {
        $html = '<table><tr><td colspan="4">PLN</td></tr><tr><th>Kode</th><th>Keterangan</th><th>Harga</th><th>Status</th></tr><tr><td>CEKPLN30</td><td>Cek PLN</td><td>0</td><td>Open</td></tr><tr><td>BYRPLN30</td><td>Bayar PLN</td><td>-500</td><td>Open</td></tr></table>';
        $rows = app(OtomaxWebCatalog::class)->parse($html, OtomaxWebCatalog::defaults());
        $this->assertSame('postpaid', $rows[0]['kind']);
        $this->assertSame(0, $rows[0]['billing']['adjustment']);
        $this->assertSame(-500, $rows[1]['billing']['adjustment']);
        $this->assertSame(0, $rows[1]['cost']);
        $this->assertTrue($rows[1]['available']);
    }

    public function test_pairing_validation_rejects_a_check_from_another_store(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $this->actingAs($f['owner'])->patch('/stores/'.$f['store']->id.'/products/'.$f['product']->id, ['code' => 'PLN30', 'name' => 'PLN', 'description' => '', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'active' => true, 'mapping_ids' => [$f['pay']->id], 'use_stock' => false,
            'postpaid_pairs' => [$f['pay']->id => ['inquiry_id' => $other['check']->id, 'admin' => 3000]]])->assertSessionHasErrors('postpaid_pairs');
        $this->assertSame($f['check']->id, $f['mapping']->fresh()->postpaid['inquiry_id']);
    }

    public function test_digiflazz_uses_one_sku_and_distinct_commands_preserving_commission_plus_markup(): void
    {
        $f = $this->fixture();
        $connection = SupplierConnection::create(['store_id' => $f['store']->id, 'kind' => 'digiflazz', 'enabled' => true, 'verified_at' => now(), 'synced_at' => now(), 'catalog_modes' => ['pasca'], 'credentials' => ['username' => 'TEST', 'api_key' => 'TEST-KEY']]);
        $item = SupplierProduct::create(['store_id' => $f['store']->id, 'connection_id' => $connection->id, 'sku' => 'pln', 'mode' => 'pasca', 'name' => 'PLN', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'cost' => 3000, 'billing' => ['admin' => 3000, 'commission' => 500], 'available' => true, 'observed_at' => now()]);
        $f['mapping']->update(['supplier_product_id' => $item->id, 'source' => 'digiflazz', 'postpaid' => null]);
        $inquiry = $this->quote($f);
        $this->assertSame('ready', $inquiry->status);
        $this->assertSame(104000, $inquiry->amount);
        $this->assertSame(102500, $inquiry->cost);
        $this->assertSame(500, $inquiry->data['commission']);
        $order = $this->order($f, $inquiry);
        $this->assertSame($inquiry->reference, $order->attempts()->first()->reference);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertSame(['inq-pasca', 'inq-pasca', 'pay-pasca'], $requests->map(fn ($r) => $r['commands'])->all());
        $this->assertCount(1, $requests->map(fn ($r) => $r['buyer_sku_code'])->unique());
        $this->assertSame(96000, $f['customer']->fresh()->balance);
    }

    public function test_closed_or_stale_check_product_blocks_the_pair(): void
    {
        $f = $this->fixture();
        $f['check']->update(['available' => false]);
        $this->assertFalse(app(StoreCatalog::class)->present(app(StoreCatalog::class)->query($f['store'], false)->findOrFail($f['product']->id), $f['store'])['available']);
        $f['check']->update(['available' => true, 'observed_at' => now()->subDays(2)]);
        $this->assertFalse(app(StoreCatalog::class)->present(app(StoreCatalog::class)->query($f['store'], false)->findOrFail($f['product']->id), $f['store'])['available']);
    }

    public function test_expired_pending_key_does_not_resend_an_inquiry(): void
    {
        $this->responseMode = 'pending';
        $f = $this->fixture();
        $this->quote($f);
        $this->travel(6)->minutes();
        try {
            $this->quote($f);
            $this->fail('Expired inquiry must not be resent');
        } catch (ValidationException) {
        }
        Http::assertSentCount(1);
    }

    public function test_plain_failed_check_exposes_a_safe_reason_and_logs_full_response_without_charging(): void
    {
        $this->responseMode = 'failed';
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $this->assertSame('failed', $inquiry->status);
        $public = app(CustomerInquiryService::class)->present($inquiry);
        $this->assertStringContainsString('Nomor tujuan salah', $public['message']);
        $this->assertArrayNotHasKey('supplier_message', $public);
        $this->assertArrayNotHasKey('commission', $public);
        $event = OtomaxEvent::where('reference', $inquiry->reference)->firstOrFail();
        $this->assertSame('inquiry', $event->operation);
        $this->assertSame('failed', $event->status);
        $this->assertStringContainsString('GAGAL. Nomor tujuan salah. Sal 941', $event->data['message']);
        $inquiry->update(['data' => [...$inquiry->data, 'failure_reason' => 'SECRET pin=1234 supplier saldo=941']]);
        $this->assertStringNotContainsString('SECRET', json_encode(app(CustomerInquiryService::class)->present($inquiry->fresh())));
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertSame(200000, $f['customer']->fresh()->balance);
    }

    public function test_failed_check_callback_updates_inquiry_and_unknown_format_is_visible_without_exposing_raw_supplier_data(): void
    {
        $this->responseMode = 'pending';
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $path = '/api/commerce/otomax/'.$f['connection']->id.'/'.str_repeat('p', 48);
        $message = 'Cek PLN Admin 2.000 CEKPLN30.12345678 GAGAL. Nomor tujuan salah. Sal 941 @09:39 R#'.$inquiry->reference;
        $this->postJson($path, ['refid' => $inquiry->reference, 'message' => $message])->assertOk();
        $this->assertSame('failed', $inquiry->fresh()->status);
        $this->assertStringContainsString('Nomor tujuan salah', app(CustomerInquiryService::class)->present($inquiry->fresh())['message']);
        $this->assertDatabaseCount('customer_orders', 0);
        $unknown = $this->quote($f, 'unknown-key');
        app(OtomaxInquiries::class)->apply($unknown, ['status' => 'pending', 'error' => 'unmatched', 'fields' => [], 'text' => 'PRIVATE-RAW-MESSAGE']);
        $view = app(CustomerInquiryService::class)->present($unknown->fresh());
        $this->assertStringContainsString('penangkap jawaban', $view['message']);
        $this->assertStringNotContainsString('PRIVATE-RAW-MESSAGE', json_encode($view));
    }

    public function test_failed_bill_parser_keeps_reference_validation_and_rejects_contradicting_query(): void
    {
        $parser = app(OtomaxParser::class);
        $message = 'Cek PLN Admin 2.000 CEKPLN20.530000000001 GAGAL. Nomor tujuan salah. Sal 941 @09:39 R#DTTEST123';
        $parsed = $parser->parse(OtomaxParser::defaults(), 'transaction', $message);
        $this->assertSame('failed', $parsed['status']);
        $this->assertSame('CEKPLN20', $parsed['fields']['kode_produk']);
        $this->assertSame('530000000001', $parsed['fields']['tujuan']);
        $this->assertSame('Nomor tujuan salah', $parsed['fields']['keterangan']);
        $this->assertSame('DTTEST123', $parsed['fields']['refid']);
        foreach (['status' => '20', 'refid' => 'OTHER'] as $key => $value) {
            $conflict = $parser->parse(OtomaxParser::defaults(), 'callback', ['message' => $message, $key => $value]);
            $this->assertSame('pending', $conflict['status']);
            $this->assertNotNull($conflict['error']);
        }
        $examples = array_values(array_filter(OtomaxParser::examples(), fn ($rule) => $rule['event'] === 'transaction'));
        $parser->validate([...OtomaxParser::defaults(), 'rules' => $examples]);
        $this->assertSame('failed', $parser->parse([...OtomaxParser::defaults(), 'rules' => $examples], 'transaction', $message)['status']);
    }

    public function test_initial_success_check_example_reads_bill_sn_with_refid_from_query(): void
    {
        $parser = app(OtomaxParser::class);
        $config = [...OtomaxParser::defaults(), 'rules' => array_values(array_filter(OtomaxParser::examples(), fn ($rule) => $rule['event'] === 'transaction'))];
        $message = '#26554524632809 CEKPDAM1.60000000 SUKSES. SN/Ref: NAMA:PELANGGAN/PTAG:202609/JBLN:1/SM:00000000-00000020/TAG:87684/ADM::2500/TTAG:90184 RC:00 INQUIRY. Saldo 271.046 - 0 = 271.046 @28/10 20:15:21 R#';
        $parsed = $parser->parse($config, 'transaction', ['refid' => 'DTINQUIRY123', 'message' => $message]);
        $this->assertNull($parsed['error']);
        $this->assertSame('success', $parsed['status']);
        $this->assertSame('CEKPDAM1', $parsed['fields']['kode_produk']);
        $this->assertSame('DTINQUIRY123', $parsed['fields']['refid']);
        $values = app(PostpaidBilling::class)->otomax($parsed['fields'], -500, 1000);
        $this->assertSame(90684, $values['amount']);
    }

    public function test_uncertain_transport_is_logged_and_not_resent_while_waiting_for_callback(): void
    {
        $this->responseMode = 'timeout';
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $this->assertSame('pending', $inquiry->status);
        $this->assertTrue($inquiry->data['transport_uncertain']);
        $event = OtomaxEvent::where('reference', $inquiry->reference)->firstOrFail();
        $this->assertSame('unknown', $event->status);
        $view = app(CustomerInquiryService::class)->present($inquiry);
        $this->assertStringContainsString('belum memperoleh respons pasti', $view['message']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($view));
        $this->quote($f, 'retry-key');
        $this->assertCount(1, $this->requests);
        $path = '/api/commerce/otomax/'.$f['connection']->id.'/'.str_repeat('p', 48);
        $text = 'CEKPLN30.12345678 GAGAL. TAGIHAN SUDAH TERBAYAR. Saldo 941 @10:05 R#'.$inquiry->reference;
        $this->postJson($path, ['refid' => $inquiry->reference, 'status' => '40', 'message' => $text])->assertOk();
        $this->assertSame('failed', $inquiry->fresh()->status);
        $this->assertArrayNotHasKey('transport_uncertain', $inquiry->fresh()->data);
        $this->assertSame('Tagihan sudah dibayar / lunas.', app(CustomerInquiryService::class)->present($inquiry->fresh())['message']);
        $this->assertDatabaseCount('customer_orders', 0);
        $inquiry->refresh()->update(['data' => [...$inquiry->data, 'failure_reason' => '']]);
        $this->assertSame('Tagihan sudah dibayar / lunas.', app(CustomerInquiryService::class)->present($inquiry->fresh())['message']);
    }

    public function test_configured_description_is_exposed_verbatim_for_failed_and_ready_inquiries(): void
    {
        $this->responseMode = 'pending';
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $config = OtomaxParser::defaults();
        $config['rules'] = [['name' => 'Keterangan custom', 'event' => 'transaction', 'active' => true, 'outcome' => 'failed',
            'template' => 'Cek PLN Admin 2.000 [kode_produk].[tujuan] [status]. [keterangan]. Sal [saldo] @[jam] R#[refid]']];
        $message = 'Cek PLN Admin 2.000 CEKPLN30.12345678 GAGAL. PEMBAYARAN HANYA DAPAT DILAKUKAN PUKUL 08.00-20.00. Sal 941 @09:39 R#'.$inquiry->reference;
        $parsed = app(OtomaxParser::class)->parse($config, 'transaction', $message);
        $this->assertContains('Keterangan custom', $parsed['matched']);
        $parsed['text'] = $message;
        app(OtomaxInquiries::class)->apply($inquiry, $parsed);
        $public = app(CustomerInquiryService::class)->present($inquiry->fresh());
        $this->assertSame('failed', $public['status']);
        $this->assertSame('PEMBAYARAN HANYA DAPAT DILAKUKAN PUKUL 08.00-20.00', $public['description']);
        $this->assertArrayNotHasKey('supplier_message', $public);
        $this->assertArrayNotHasKey('otomax', $public);
        $ready = $this->quote($f, 'ready-description');
        app(OtomaxInquiries::class)->apply($ready, ['status' => 'success', 'error' => null, 'fields' => ['refid' => $ready->reference, 'kode_produk' => 'CEKPLN30', 'tujuan' => '12345678', 'keterangan' => 'TAGIHAN 2 BULAN', 'nama_pelanggan' => 'TEST', 'tagihan' => '100000', 'admin' => '3000', 'total_tagihan' => '103000'], 'text' => 'PRIVATE SUPPLIER BALANCE']);
        $public = app(CustomerInquiryService::class)->present($ready->fresh());
        $this->assertSame('ready', $public['status']);
        $this->assertSame('TAGIHAN 2 BULAN', $public['description']);
        $this->assertStringNotContainsString('PRIVATE SUPPLIER BALANCE', json_encode($public));
        $this->assertDatabaseCount('customer_orders', 0);
    }

    public function test_five_percent_markup_is_store_admin_without_changing_service_admin_or_supplier_commission(): void
    {
        $billing = app(PostpaidBilling::class);
        $markup = $billing->storeFee(500, '5%');
        $values = $billing->amounts(101500, 102000, 2000, $markup, 'Pelanggan', '202610');
        $this->assertSame(2000, $values['admin']);
        $this->assertSame(2000, $values['supplier_admin']);
        $this->assertSame(-475, $values['store_admin']);
        $this->assertSame(500, $values['commission']);
        $this->assertSame(101525, $values['amount']);
        $this->assertSame(['bill' => 100000, 'service_admin' => 2000, 'store_admin' => -475], $billing->publicBreakdown($values));
        $this->assertNull($billing->publicBreakdown(['admin' => 2100]));
        $f = $this->fixture();
        $f['product']->update(['markup' => '5%', 'auto_update' => true]);
        $quote = app(StoreCatalog::class)->present(app(StoreCatalog::class)->query($f['store'], false)->findOrFail($f['product']->id), $f['store']);
        $this->assertSame(['service_admin' => 3000, 'store_admin' => -475], $quote['postpaid_fees']);
        $inquiry = $this->quote($f);
        $this->assertSame(['bill' => 100000, 'service_admin' => 3000, 'store_admin' => -475], app(CustomerInquiryService::class)->present($inquiry)['billing']);
        $this->assertSame(102525, $inquiry->amount);
        $order = $this->order($f, $inquiry);
        app(CustomerFulfillment::class)->run($order);
        $public = app(CustomerCommerce::class)->publicData($order->fresh());
        $this->assertSame('success', $public['status']);
        $this->assertSame(102525, $public['amount']);
        $this->assertSame(3000, $public['postpaid_breakdown']['service_admin']);
        $this->assertSame(-475, $public['postpaid_breakdown']['store_admin']);
        $this->assertArrayNotHasKey('commission', $public['postpaid_breakdown']);
        $this->assertSame(97475, $f['customer']->fresh()->balance);
        $notice = app(CustomerNoticeText::class)->order($order->fresh(), 'success', 'https://example.test');
        $this->assertStringContainsString('Admin layanan: Rp3.000', $notice);
        $this->assertStringContainsString('Admin toko: Rp-475', $notice);
    }

    public function test_legacy_combined_admin_is_split_without_repricing_a_confirmed_inquiry(): void
    {
        $f = $this->fixture();
        $inquiry = $this->quote($f);
        $data = $inquiry->data;
        unset($data['store_admin']);
        $data['admin'] = 4000;
        $inquiry->update(['data' => $data]);
        $public = app(CustomerInquiryService::class)->present($inquiry->fresh());
        $this->assertSame(3000, $public['admin']);
        $this->assertSame(['bill' => 100000, 'service_admin' => 3000, 'store_admin' => 1000], $public['billing']);
        $this->assertSame(104000, $public['amount']);
        $order = $this->order($f, $inquiry->fresh());
        $this->assertSame(104000, $order->amount);
        $this->assertSame(1000, app(CustomerCommerce::class)->publicData($order)['postpaid_breakdown']['store_admin']);
    }

    public function test_provider_adjustment_signs_are_preserved_and_markup_uses_their_magnitude(): void
    {
        $billing = app(PostpaidBilling::class);
        $this->assertSame(-500, $billing->sourceAdjustment('otomax', ['adjustment' => -500]));
        $this->assertSame(-500, $billing->sourceAdjustment('digiflazz', ['commission' => 500]));
        $this->assertSame(500, $billing->sourceAdjustment('otomax', ['adjustment' => 500]));
        $this->assertSame(25, $billing->storeFee(500, '5%'));
        $this->assertSame(20, $billing->storeFee(400, '5%'));
        $this->assertSame(5025, $billing->storeFee(500, '5% + 5000'));
        $this->assertSame(0, $billing->storeFee(0, '5%'));
        $this->assertSame(100, $billing->storeFee(0, '5% + 100'));
    }

    public function test_otomax_commission_changes_reprice_new_quotes_while_paid_orders_keep_their_store_admin(): void
    {
        $f = $this->fixture();
        $f['product']->update(['markup' => '5%', 'auto_update' => true]);
        $old = $this->quote($f, 'old-fee');
        $order = $this->order($f, $old);
        $fixed = CatalogProduct::create(['store_id' => $f['store']->id, 'code' => 'FIXED-PASCA', 'name' => 'Harga tetap', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'sale_price' => 4000, 'markup' => '5%', 'auto_update' => false, 'active' => true]);
        ProductMapping::create(['store_id' => $f['store']->id, 'product_id' => $fixed->id, 'source' => 'otomax', 'source_key' => 'item:'.$f['pay']->id, 'supplier_product_id' => $f['pay']->id, 'active' => true, 'postpaid' => $f['mapping']->postpaid]);
        $f['pay']->update(['billing' => ['adjustment' => -400]]);
        $this->otomaxAdjustment = -400;
        app(StoreCatalog::class)->reprice($f['store']);
        $this->assertSame(2620, $f['product']->fresh()->sale_price);
        $this->assertSame(4000, $fixed->fresh()->sale_price);
        $fresh = $this->quote($f, 'new-fee');
        $this->assertNotSame($old->id, $fresh->id);
        $this->assertSame(102525, $old->fresh()->amount);
        $this->assertSame(102620, $fresh->amount);
        $this->assertSame(-380, $fresh->data['store_admin']);
        $this->assertSame(400, $fresh->data['commission']);
        $this->assertSame(102600, $fresh->cost);
        $this->assertSame($old->id, $this->quote($f, 'old-fee')->id);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('review', $order->fresh()->status);
        $this->assertSame(102525, $order->fresh()->amount);
        $this->assertSame(-475, app(CustomerCommerce::class)->publicData($order->fresh())['postpaid_breakdown']['store_admin']);
        $this->assertSame(102500, $order->attempts()->first()->cost);
    }

    public function test_digiflazz_positive_commission_changes_invalidate_only_new_quote_keys(): void
    {
        $f = $this->fixture();
        $connection = SupplierConnection::create(['store_id' => $f['store']->id, 'kind' => 'digiflazz', 'enabled' => true, 'verified_at' => now(), 'synced_at' => now(), 'catalog_modes' => ['pasca'], 'credentials' => ['username' => 'TEST', 'api_key' => 'TEST-KEY']]);
        $item = SupplierProduct::create(['store_id' => $f['store']->id, 'connection_id' => $connection->id, 'sku' => 'pln', 'mode' => 'pasca', 'name' => 'PLN', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'cost' => 3000, 'billing' => ['admin' => 3000, 'commission' => 500], 'available' => true, 'observed_at' => now()]);
        $f['mapping']->update(['supplier_product_id' => $item->id, 'source' => 'digiflazz', 'postpaid' => null]);
        $f['product']->update(['markup' => '5%', 'auto_update' => true]);
        $old = $this->quote($f, 'first-digi');
        $this->assertSame(102525, $old->amount);
        $item->update(['billing' => ['admin' => 3000, 'commission' => 400]]);
        $this->digiCommission = 400;
        app(StoreCatalog::class)->reprice($f['store']);
        $this->assertSame(2620, $f['product']->fresh()->sale_price);
        $fresh = $this->quote($f, 'next-digi');
        $this->assertNotSame($old->id, $fresh->id);
        $this->assertSame(102620, $fresh->amount);
        $this->assertSame(102600, $fresh->cost);
        $this->assertSame(400, $fresh->data['commission']);
        $this->assertSame($old->id, $this->quote($f, 'first-digi')->id);
        $this->assertSame(102525, $old->fresh()->amount);
        $this->assertSame(-380, app(CustomerInquiryService::class)->present($fresh)['billing']['store_admin']);
    }

    public function test_async_check_uses_current_commission_when_it_becomes_ready_and_then_freezes_the_quote(): void
    {
        $this->responseMode = 'pending';
        $f = $this->fixture();
        $f['product']->update(['markup' => '5%', 'auto_update' => true]);
        $inquiry = $this->quote($f, 'pending-fee');
        $f['pay']->update(['billing' => ['adjustment' => -400]]);
        $this->assertSame($inquiry->id, $this->quote($f, 'another-pending-key')->id);
        Http::assertSentCount(1);
        $parsed = ['status' => 'success', 'error' => null, 'fields' => ['refid' => $inquiry->reference, 'kode_produk' => 'CEKPLN30', 'tujuan' => '12345678', 'nama_pelanggan' => 'TEST', 'tagihan' => '100000', 'admin' => '3000', 'total_tagihan' => '103000'], 'text' => 'CEK SIMULASI'];
        app(OtomaxInquiries::class)->apply($inquiry, $parsed);
        $this->assertSame(-380, $inquiry->fresh()->data['store_admin']);
        $f['pay']->update(['billing' => ['adjustment' => -300]]);
        app(OtomaxInquiries::class)->apply($inquiry, $parsed);
        $this->assertSame(-380, $inquiry->fresh()->data['store_admin']);
        $this->assertSame(102620, $inquiry->fresh()->amount);
    }

    public function test_new_fixed_price_product_without_explicit_price_initializes_markup_from_commission(): void
    {
        $f = $this->fixture();
        $this->actingAs($f['owner'])->post('/stores/'.$f['store']->id.'/products', ['code' => 'PLN-NEW', 'name' => 'PLN baru', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'active' => true, 'mapping_ids' => [$f['pay']->id], 'use_stock' => false, 'markup' => '5%', 'auto_update' => false, 'postpaid_pairs' => [$f['pay']->id => ['inquiry_id' => $f['check']->id, 'admin' => 3000]]])->assertSessionHasNoErrors();
        $this->assertSame(2525, CatalogProduct::where('store_id', $f['store']->id)->where('code', 'PLN-NEW')->firstOrFail()->sale_price);
    }

    public function test_signed_otomax_adjustment_changes_supplier_cost_and_customer_total_with_nominal_markup(): void
    {
        $billing = app(PostpaidBilling::class);
        foreach ([-500 => 102500, 500 => 103500] as $adjustment => $expected) {
            $values = $billing->otomax(['tagihan' => '100000', 'admin' => '2000', 'total_tagihan' => '102000', 'nama_pelanggan' => 'TEST'], $adjustment, 1000);
            $this->assertSame(102000 + $adjustment, $values['cost']);
            $this->assertSame($expected, $values['amount']);
            $this->assertSame(1000, $values['amount'] - $values['cost']);
            $this->assertSame($adjustment + 1000, $values['store_admin']);
            $this->assertSame(2000, $values['supplier_admin']);
        }
        $f = $this->fixture();
        $f['product']->update(['markup' => '1000', 'auto_update' => true]);
        $negative = $this->quote($f, 'minus-adjustment');
        $this->assertSame(103500, $negative->amount);
        $this->assertSame(102500, $negative->cost);
        $f['pay']->update(['cost' => 500, 'billing' => ['adjustment' => 500]]);
        $this->otomaxAdjustment = 500;
        app(StoreCatalog::class)->reprice($f['store']);
        $positive = $this->quote($f, 'plus-adjustment');
        $this->assertSame(4500, $f['product']->fresh()->sale_price);
        $this->assertSame(104500, $positive->amount);
        $this->assertSame(103500, $positive->cost);
        $this->assertSame(1000, $positive->data['markup']);
        $this->assertSame(1500, $positive->data['store_admin']);
        $order = $this->order($f, $positive);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(95500, $f['customer']->fresh()->balance);
        $this->assertSame(103500, $order->attempts()->first()->cost);
        $this->assertSame(103500, $negative->fresh()->amount);
    }

    public function test_postpaid_display_uses_highest_net_cost_but_otomax_inquiry_and_payment_use_cheapest_source(): void
    {
        $f = $this->fixture();
        $f['product']->update(['auto_update' => true, 'markup' => '10%']);
        $connection = SupplierConnection::create(['store_id' => $f['store']->id, 'kind' => 'digiflazz', 'enabled' => true, 'verified_at' => now(), 'synced_at' => now(), 'catalog_modes' => ['pasca'], 'credentials' => ['username' => 'TEST', 'api_key' => 'TEST-KEY']]);
        $item = SupplierProduct::create(['store_id' => $f['store']->id, 'connection_id' => $connection->id, 'sku' => 'pln', 'mode' => 'pasca', 'name' => 'PLN', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'cost' => 3000, 'billing' => ['admin' => 3000, 'commission' => 100], 'available' => true, 'observed_at' => now()]);
        ProductMapping::create(['store_id' => $f['store']->id, 'product_id' => $f['product']->id, 'source' => 'digiflazz', 'source_key' => 'item:'.$item->id, 'supplier_product_id' => $item->id, 'active' => true]);
        $catalog = app(StoreCatalog::class);
        $quote = $catalog->present($catalog->query($f['store'], false)->findOrFail($f['product']->id), $f['store'], true);
        $this->assertSame(2910, $quote['price']);
        $this->assertSame(['otomax', 'digiflazz'], array_column($quote['candidates'], 'source'));
        $inquiry = $this->quote($f, 'highest-postpaid');
        $this->assertSame(102910, $inquiry->amount);
        $this->assertSame(102500, $inquiry->cost);
        $this->assertSame(410, $inquiry->data['markup']);
        PricingRule::create(['store_id' => $f['store']->id, 'scope' => 'category', 'key' => mb_strtolower($f['product']->category), 'pricing_basis' => 'cheapest']);
        app(ProductPricing::class)->forget();
        $cheaper = $this->quote($f, 'lowest-postpaid');
        $this->assertSame(102550, $cheaper->amount);
        $this->assertSame(102910, $inquiry->fresh()->amount);
        $order = $this->order($f, $inquiry);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('otomax', $order->attempts()->where('status', 'success')->sole()->source);
        $this->assertSame(97090, $f['customer']->fresh()->balance);
        Http::assertSentCount(3);
    }

    public function test_digiflazz_inquiry_uses_highest_otomax_modal_without_dispatching_that_supplier(): void
    {
        $f = $this->fixture();
        $f['product']->update(['auto_update' => true, 'markup' => '10%']);
        $f['pay']->update(['billing' => ['adjustment' => 500], 'cost' => 500]);
        $connection = SupplierConnection::create(['store_id' => $f['store']->id, 'kind' => 'digiflazz', 'enabled' => true, 'verified_at' => now(), 'synced_at' => now(), 'catalog_modes' => ['pasca'], 'credentials' => ['username' => 'TEST', 'api_key' => 'TEST-KEY']]);
        $item = SupplierProduct::create(['store_id' => $f['store']->id, 'connection_id' => $connection->id, 'sku' => 'pln', 'mode' => 'pasca', 'name' => 'PLN', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'cost' => 3000, 'billing' => ['admin' => 3000, 'commission' => 500], 'available' => true, 'observed_at' => now()]);
        ProductMapping::create(['store_id' => $f['store']->id, 'product_id' => $f['product']->id, 'source' => 'digiflazz', 'source_key' => 'item:'.$item->id, 'supplier_product_id' => $item->id, 'active' => true]);
        $inquiry = $this->quote($f, 'highest-otomax');
        $this->assertSame(103550, $inquiry->amount);
        $this->assertSame(102500, $inquiry->cost);
        $this->assertSame('digiflazz', $inquiry->data['candidates'][0]['source']);
        $this->assertSame([], $this->requests);
        $this->assertSame(550, app(CustomerInquiryService::class)->present($inquiry)['billing']['store_admin']);
        PricingRule::create(['store_id' => $f['store']->id, 'scope' => 'category', 'key' => mb_strtolower($f['product']->category), 'pricing_basis' => 'cheapest']);
        app(ProductPricing::class)->forget();
        $cheap = $this->quote($f, 'cheapest-digiflazz');
        $this->assertSame(102550, $cheap->amount);
        $this->assertSame(103550, $inquiry->fresh()->amount);
        $this->assertSame('digiflazz', $cheap->data['candidates'][0]['source']);
    }

    private function addDigiflazzFallback(array $f, int $commission = 100): void
    {
        $connection = SupplierConnection::create(['store_id' => $f['store']->id, 'kind' => 'digiflazz', 'enabled' => true, 'verified_at' => now(), 'synced_at' => now(), 'catalog_modes' => ['pasca'], 'credentials' => ['username' => 'TEST', 'api_key' => 'TEST-KEY']]);
        $item = SupplierProduct::create(['store_id' => $f['store']->id, 'connection_id' => $connection->id, 'sku' => 'pln', 'mode' => 'pasca', 'name' => 'PLN', 'kind' => 'postpaid', 'service' => 'Pascabayar', 'category' => 'PLN', 'cost' => 3000, 'billing' => ['admin' => 3000, 'commission' => $commission], 'available' => true, 'observed_at' => now()]);
        ProductMapping::create(['store_id' => $f['store']->id, 'product_id' => $f['product']->id, 'source' => 'digiflazz', 'source_key' => 'item:'.$item->id, 'supplier_product_id' => $item->id, 'active' => true]);
        $this->digiCommission = $commission;
        $this->digiName = 'PELANGGAN';
        $this->digiPeriod = '';
    }

    public function test_otomax_definitive_failure_fails_over_to_digiflazz_without_another_customer_charge(): void
    {
        $f = $this->fixture();
        $this->addDigiflazzFallback($f);
        $this->paymentStatus = 'failed';
        $inquiry = $this->quote($f);
        $order = $this->order($f, $inquiry);
        $this->assertSame([102500, 102900], $order->attempts()->orderBy('position')->pluck('cost')->all());
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(['failed', 'success'], $order->attempts()->orderBy('position')->pluck('status')->all());
        $this->assertSame(96000, $f['customer']->fresh()->balance);
        $this->assertNull($order->fresh()->refunded_at);
        app(CustomerFulfillment::class)->run($order->fresh());
        $payments = Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.digiflazz.com') && $pair[0]['commands'] === 'pay-pasca');
        $this->assertCount(1, $payments);
    }

    public function test_pending_payment_never_checks_or_pays_another_source(): void
    {
        $f = $this->fixture();
        $this->addDigiflazzFallback($f);
        $this->paymentStatus = 'pending';
        $order = $this->order($f, $this->quote($f));
        app(CustomerFulfillment::class)->run($order);
        $this->travel(2)->minutes();
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('ready', $order->attempts()->where('position', 2)->sole()->status);
        Http::assertSentCount(2);
    }

    public function test_mismatched_fallback_bill_is_not_paid_and_refunds_only_once(): void
    {
        $f = $this->fixture();
        $this->addDigiflazzFallback($f);
        $this->digiName = 'OTHER CUSTOMER';
        $this->paymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        app(CustomerFulfillment::class)->run($order);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(200000, $f['customer']->fresh()->balance);
        $this->assertSame(1, CustomerLedger::where('reason', 'refund')->count());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.digiflazz.com') && $request['commands'] === 'pay-pasca');
    }

    public function test_digiflazz_failure_waits_for_otomax_inquiry_callback_before_fallback_payment(): void
    {
        $f = $this->fixture();
        $f['pay']->update(['cost' => 500, 'billing' => ['adjustment' => 500]]);
        $this->otomaxAdjustment = 500;
        $this->addDigiflazzFallback($f, 500);
        $this->digiPaymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        $this->responseMode = 'pending';
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('pending', $order->fresh()->status);
        $fallback = $order->attempts()->where('position', 2)->sole();
        $check = CustomerInquiry::findOrFail($fallback->request_snapshot['fallback_inquiry_id']);
        $this->assertSame('pending', $check->status);
        $this->assertSame(['CEKPLN30'], array_column($this->requests, 'product'));
        app(OtomaxInquiries::class)->apply($check, ['status' => 'success', 'error' => null, 'fields' => ['refid' => $check->reference, 'kode_produk' => 'CEKPLN30', 'tujuan' => '12345678', 'nama_pelanggan' => 'PELANGGAN', 'tagihan' => '100000', 'admin' => '3000', 'total_tagihan' => '103000'], 'text' => 'TEST']);
        $this->assertSame($order->id, $check->fresh()->data['failover_order_id']);
        try {
            $this->order($f, $check->fresh());
            $this->fail('Internal fallback inquiry cannot create another order');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('internal', implode(' ', $error->errors()['order'] ?? $error->validator->errors()->all()));
        }
        $this->travel(61)->seconds();
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(['CEKPLN30', 'BYRPLN30'], array_column($this->requests, 'product'));
        $this->assertSame(96000, $f['customer']->fresh()->balance);
    }

    public function test_exhausted_postpaid_sources_refund_once_and_over_budget_source_is_never_paid(): void
    {
        $f = $this->fixture();
        $this->addDigiflazzFallback($f);
        $this->paymentStatus = 'failed';
        $this->digiPaymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        app(CustomerFulfillment::class)->run($order);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(200000, $f['customer']->fresh()->balance);
        $this->assertSame(1, CustomerLedger::where('reason', 'refund')->count());
    }

    public function test_cheapest_pricing_does_not_allow_more_expensive_fallback_above_locked_total(): void
    {
        $f = $this->fixture();
        $this->addDigiflazzFallback($f, 100);
        $f['product']->update(['markup' => '0', 'auto_update' => true]);
        PricingRule::create(['store_id' => $f['store']->id, 'scope' => 'category', 'key' => 'pln', 'pricing_basis' => 'cheapest']);
        $this->paymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        $this->assertSame(102500, $order->amount);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(200000, $f['customer']->fresh()->balance);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.digiflazz.com'));
    }

    public function test_late_success_from_failed_digiflazz_source_marks_double_payment_review(): void
    {
        $f = $this->fixture();
        $f['pay']->update(['cost' => 500, 'billing' => ['adjustment' => 500]]);
        $this->otomaxAdjustment = 500;
        $this->addDigiflazzFallback($f, 500);
        $this->digiPaymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $first = $order->attempts()->where('position', 1)->sole();
        app(CustomerFulfillment::class)->digiflazzCallback($order->fresh(), $first, ['status' => 'Sukses', 'rc' => '00', 'price' => 102500, 'sn' => 'LATE-SUCCESS']);
        $this->assertSame('review', $order->fresh()->status);
        $this->assertSame(96000, $f['customer']->fresh()->balance);
        $this->assertNull($order->fresh()->refunded_at);
    }

    public function test_otomax_to_otomax_uses_each_suppliers_own_credentials_and_check_sku(): void
    {
        $f = $this->fixture();
        $connection = $f['connection']->replicate();
        $connection->fill(['slot' => (string) Str::uuid(), 'credentials' => ['member_id' => 'DEMO2', 'pin' => '1234', 'password' => 'OTHER-TEST', 'callback_token' => str_repeat('q', 48)]])->save();
        $check = $f['check']->replicate();
        $check->fill(['connection_id' => $connection->id, 'sku' => 'CEKPLN40'])->save();
        $pay = $f['pay']->replicate();
        $pay->fill(['connection_id' => $connection->id, 'sku' => 'BYRPLN40', 'billing' => ['adjustment' => -100]])->save();
        ProductMapping::create(['store_id' => $f['store']->id, 'product_id' => $f['product']->id, 'source' => 'otomax', 'source_key' => 'item:'.$pay->id, 'supplier_product_id' => $pay->id, 'active' => true, 'postpaid' => ['inquiry_id' => $check->id, 'admin' => 3000]]);
        $this->failedSkus = ['BYRPLN30'];
        $order = $this->order($f, $this->quote($f));
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(['CEKPLN30', 'BYRPLN30', 'CEKPLN40', 'BYRPLN40'], array_column($this->requests, 'product'));
        $this->assertSame(['DEMO', 'DEMO', 'DEMO2', 'DEMO2'], array_column($this->requests, 'memberID'));
        $this->assertNotSame($this->requests[1]['refID'], $this->requests[3]['refID']);
        $this->assertSame(96000, $f['customer']->fresh()->balance);
    }

    public function test_uncertain_fallback_inquiry_is_not_repeated_and_never_sends_payment(): void
    {
        $f = $this->fixture();
        $f['pay']->update(['cost' => 500, 'billing' => ['adjustment' => 500]]);
        $this->addDigiflazzFallback($f, 500);
        $this->digiPaymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        $this->responseMode = 'timeout';
        app(CustomerFulfillment::class)->run($order);
        $this->travel(61)->seconds();
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(['CEKPLN30'], array_column($this->requests, 'product'));
        $this->assertSame(96000, $f['customer']->fresh()->balance);
        $this->travel(5)->minutes();
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(200000, $f['customer']->fresh()->balance);
        $this->assertSame(['CEKPLN30'], array_column($this->requests, 'product'));
    }

    public function test_confirmed_failed_payment_webhook_queues_and_continues_postpaid_fallback(): void
    {
        $f = $this->fixture();
        $f['pay']->update(['cost' => 500, 'billing' => ['adjustment' => 500]]);
        $this->otomaxAdjustment = 500;
        $this->addDigiflazzFallback($f, 500);
        $this->digiPaymentStatus = 'pending';
        $order = $this->order($f, $this->quote($f));
        app(CustomerFulfillment::class)->run($order);
        $first = $order->attempts()->where('position', 1)->sole();
        app(CustomerFulfillment::class)->digiflazzCallback($order->fresh(), $first, ['status' => 'Gagal', 'rc' => '44', 'price' => 102500]);
        Queue::assertPushed(ProcessCustomerOrder::class);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(96000, $f['customer']->fresh()->balance);
        $this->assertSame(['CEKPLN30', 'BYRPLN30'], array_column($this->requests, 'product'));
    }

    public function test_period_formats_match_but_different_bill_admin_customer_or_period_do_not(): void
    {
        $f = $this->fixture();
        $order = $this->order($f, $this->quote($f));
        $snapshot = $order->snapshot;
        $snapshot['bill_period'] = 'SEPTEMBER 2026';
        $order->update(['snapshot' => $snapshot]);
        $values = ['bill' => 100000, 'supplier_admin' => 3000, 'customer_name' => 'pelanggan', 'period' => '202609'];
        $service = app(PostpaidFailover::class);
        $this->assertTrue($service->matches($order, $values));
        foreach (['bill' => 100001, 'supplier_admin' => 3500, 'customer_name' => 'OTHER', 'period' => '202610'] as $key => $value) {
            $this->assertFalse($service->matches($order, [...$values, $key => $value]));
        }
    }

    public function test_multiple_periods_match_as_complete_month_sets_without_accepting_partial_or_duplicate_bills(): void
    {
        $order = new CustomerOrder(['snapshot' => ['bill_breakdown' => ['bill' => 20000, 'supplier_admin' => 5000], 'bill_customer' => 'PELANGGAN', 'bill_period' => '201901,201902']]);
        $values = ['bill' => 20000, 'supplier_admin' => 5000, 'customer_name' => 'pelanggan', 'period' => 'FEBRUARI 2019; JANUARI 2019'];
        $service = app(PostpaidFailover::class);
        foreach (['FEBRUARI 2019; JANUARI 2019', '02/2019 | 01/2019', "2019-02\n2019-01", '201901,201902'] as $period) {
            $this->assertTrue($service->matches($order, [...$values, 'period' => $period]), $period);
        }
        foreach (['201901', '201901,201903', '201901,201902,201903', '201901,201901', '201901,201902,201902', '201913,201901', '201901,,201902', 'JANUARI-FEBRUARI 2019', ''] as $period) {
            $this->assertFalse($service->matches($order, [...$values, 'period' => $period]), $period);
        }
    }

    private function multiPeriodFixture(): array
    {
        $f = $this->fixture();
        $this->addDigiflazzFallback($f);
        $this->bill = 20000;
        $this->admin = 5000;
        $this->otomaxPeriod = '02/2019,01/2019';
        $this->digiDetails = [['periode' => '201901'], ['periode' => '201902']];
        $f['product']->update(['sale_price' => 6000]);
        $f['mapping']->update(['postpaid' => ['inquiry_id' => $f['check']->id, 'admin' => 5000]]);
        SupplierProduct::where('store_id', $f['store']->id)->where('mode', 'pasca')->update(['cost' => 5000, 'billing' => ['admin' => 5000, 'commission' => 100]]);

        return $f;
    }

    public function test_multiple_period_otomax_failure_can_pay_digiflazz_once_with_locked_price(): void
    {
        $f = $this->multiPeriodFixture();
        $this->paymentStatus = 'failed';
        $inquiry = $this->quote($f);
        $this->assertSame('02/2019,01/2019', $inquiry->data['period']);
        $order = $this->order($f, $inquiry);
        $this->assertSame(26000, $order->amount);
        app(CustomerFulfillment::class)->run($order);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(['failed', 'success'], $order->attempts()->orderBy('position')->pluck('status')->all());
        $this->assertSame(174000, $f['customer']->fresh()->balance);
        $this->assertSame(26000, $order->fresh()->amount);
        Http::assertSentCount(5);
        Http::assertSent(fn ($request) => ($request['commands'] ?? null) === 'pay-pasca');
    }

    public function test_multiple_period_digiflazz_failure_can_pay_otomax_without_truncating_slash_months(): void
    {
        $f = $this->multiPeriodFixture();
        $f['pay']->update(['cost' => 500, 'billing' => ['adjustment' => 500]]);
        $this->otomaxAdjustment = 500;
        $this->digiPaymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        $this->assertSame('201901,201902', $order->snapshot['bill_period']);
        app(CustomerFulfillment::class)->run($order);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(['failed', 'success'], $order->attempts()->orderBy('position')->pluck('status')->all());
        $this->assertSame(174000, $f['customer']->fresh()->balance);
        $this->assertSame(['CEKPLN30', 'BYRPLN30'], array_column($this->requests, 'product'));
    }

    public function test_multiple_period_missing_month_blocks_fallback_payment_and_refunds_once(): void
    {
        $f = $this->multiPeriodFixture();
        $this->digiDetails = [['periode' => '201901']];
        $this->paymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        app(CustomerFulfillment::class)->run($order);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(200000, $f['customer']->fresh()->balance);
        $this->assertSame(1, CustomerLedger::where('reason', 'refund')->count());
        Http::assertNotSent(fn ($request) => ($request['commands'] ?? null) === 'pay-pasca');
    }

    public function test_multiple_period_pending_fallback_waits_for_callback_and_duplicate_does_not_pay_twice(): void
    {
        $f = $this->multiPeriodFixture();
        $f['pay']->update(['cost' => 500, 'billing' => ['adjustment' => 500]]);
        $this->otomaxAdjustment = 500;
        $this->digiPaymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        $this->responseMode = 'pending';
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(['CEKPLN30'], array_column($this->requests, 'product'));
        $attempt = $order->attempts()->where('position', 2)->sole();
        $check = CustomerInquiry::findOrFail($attempt->request_snapshot['fallback_inquiry_id']);
        $response = ['status' => 'success', 'error' => null, 'fields' => ['refid' => $check->reference, 'kode_produk' => 'CEKPLN30', 'tujuan' => '12345678', 'nama_pelanggan' => 'PELANGGAN', 'tagihan' => '20000', 'admin' => '5000', 'total_tagihan' => '25000', 'periode' => 'FEBRUARI 2019; JANUARI 2019'], 'text' => 'MULTI-PERIOD TEST'];
        app(OtomaxInquiries::class)->apply($check, $response);
        $this->travel(61)->seconds();
        app(CustomerFulfillment::class)->run($order->fresh());
        app(OtomaxInquiries::class)->apply($check->fresh(), $response);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(['CEKPLN30', 'BYRPLN30'], array_column($this->requests, 'product'));
        $this->assertSame(174000, $f['customer']->fresh()->balance);
        $this->assertSame(26000, $order->fresh()->amount);
        $this->assertSame(0, CustomerLedger::where('reason', 'refund')->count());
    }

    public function test_paid_postpaid_order_can_inquire_and_pay_fallback_during_platform_maintenance(): void
    {
        $f = $this->fixture();
        $f['pay']->update(['cost' => 500, 'billing' => ['adjustment' => 500]]);
        $this->otomaxAdjustment = 500;
        $this->addDigiflazzFallback($f, 500);
        $this->digiPaymentStatus = 'failed';
        $order = $this->order($f, $this->quote($f));
        MaintenanceWindow::create(['scope' => 'platform', 'enabled' => true]);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status, $order->fresh()->message);
        $this->assertSame(['failed', 'success'], $order->attempts()->orderBy('position')->pluck('status')->all());
        $this->assertSame(1, CustomerLedger::where('event', 'order:'.$order->id.':debit')->where('reason', 'purchase')->count());
    }
}
