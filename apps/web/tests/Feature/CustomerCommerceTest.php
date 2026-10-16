<?php

namespace Tests\Feature;

use App\Mail\OwnerOtpMail;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerNotice;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CommerceModules;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerInquiryService;
use App\Services\CustomerPayments;
use App\Services\CustomQris;
use App\Support\HanshopIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class CustomerCommerceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function store(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.random_int(10000, 99999)])->id, 'name' => 'Toko Tes', 'slug' => 'tes-'.Str::lower(Str::random(8)), 'template' => 'aurora', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
    }

    private function customer(Store $s): Customer
    {
        return Customer::create(['store_id' => $s->id, 'name' => 'Pelanggan Tes', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'balance' => 100000]);
    }

    private function product(Store $s, int $stock = 1): CatalogProduct
    {
        $p = CatalogProduct::create(['store_id' => $s->id, 'code' => Str::upper(Str::random(8)), 'name' => 'Akun Tes', 'kind' => 'premium', 'service' => 'Aplikasi', 'category' => 'Canva', 'sale_price' => 15000, 'active' => true]);
        ProductMapping::create(['store_id' => $s->id, 'product_id' => $p->id, 'source' => 'stock', 'source_key' => 'stock', 'active' => true]);
        for ($i = 0; $i < $stock; $i++) {
            PremiumStock::create(['store_id' => $s->id, 'product_id' => $p->id, 'payload' => 'email: dummy@example.test; password: fake-only', 'cost' => 10000]);
        }

        return $p;
    }

    private function input(CatalogProduct $p, array $extra = []): array
    {
        return [...['purpose' => 'product', 'product_id' => $p->id, 'phone' => '6281234567890', 'destination' => '6281234567890', 'payment' => 'wallet', 'idempotency_key' => (string) Str::uuid()], ...$extra];
    }

    private function gateway(Store $s, string $type = 'midtrans'): void
    {
        app(CustomerCommerce::class)->settings($s)->update(['gateway' => $type, 'credentials' => ['server_key' => 'test-only-server-key', 'qris_payload' => $this->qris(), 'forward_token' => str_repeat('f', 40), 'application_package' => 'id.dana'], 'custom_licensed_at' => $type === 'custom' ? now() : null]);
    }

    private function qris(): string
    {
        $s = '00020101021126080004TEST5204000053033605802ID5904TEST6007JAKARTA6304';
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($s); $i++) {
            $crc ^= ord($s[$i]) << 8;
            for ($j = 0; $j < 8; $j++) {
                $crc = (($crc & 0x8000) ? ($crc << 1) ^ 0x1021 : ($crc << 1)) & 0xFFFF;
            }
        }

        return $s.strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    public function test_wallet_order_is_idempotent_and_one_stock_cannot_fulfil_two_orders(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $input = $this->input($p);
        $engine = app(CustomerCommerce::class);
        $a = $engine->begin($s, $input, $c);
        $this->assertSame($a->id, $engine->begin($s, $input, $c)->id);
        $b = $engine->begin($s, $this->input($p), $c);
        app(CustomerFulfillment::class)->run($a);
        app(CustomerFulfillment::class)->run($a);
        app(CustomerFulfillment::class)->run($b);
        app(CustomerFulfillment::class)->run($b);
        $this->assertSame('success', $a->fresh()->status);
        $this->assertSame('failed', $b->fresh()->status);
        $this->assertSame(85000, $c->fresh()->balance);
        $this->assertSame(1, PremiumStock::where('status', 'sent')->count());
        $this->assertSame(1, CustomerLedger::where('reason', 'refund')->count());
        $this->assertStringContainsString('dummy@example.test', $a->fresh()->result);
        $this->assertStringNotContainsString('dummy@example.test', json_encode($a->fresh()));
        Http::assertNothingSent();
    }

    public function test_idempotency_conflict_rolls_back_without_another_debit(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $input = $this->input($p);
        app(CustomerCommerce::class)->begin($s, $input, $c);
        try {
            app(CustomerCommerce::class)->begin($s, [...$input, 'destination' => 'different'], $c);
            $this->fail('Conflict expected');
        } catch (ValidationException) {
        }
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertSame(85000, $c->fresh()->balance);
    }

    public function test_topup_credits_only_once_with_frozen_unique_code_policy(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $this->gateway($s, 'custom');
        $setting = app(CustomerCommerce::class)->settings($s);
        $setting->update(['topup_unique' => true]);
        $order = app(CustomerCommerce::class)->begin($s, ['purpose' => 'topup', 'amount' => 50000, 'phone' => $c->phone, 'destination' => $c->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $c);
        $setting->update(['topup_unique' => false]);
        app(CustomerCommerce::class)->paid($order, 'test:topup', $order->total, now());
        app(CustomerCommerce::class)->paid($order, 'test:topup', $order->total, now());
        $this->assertSame(150000 + $order->unique_code, $c->fresh()->balance);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertDatabaseCount('customer_ledgers', 1);
    }

    public function test_midtrans_topup_and_product_refund_exclude_fees(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $this->gateway($s);
        app(CustomerCommerce::class)->settings($s)->update(['fee_payer' => 'customer', 'fee_markup' => '5% + 500']);
        $topup = app(CustomerCommerce::class)->begin($s, ['purpose' => 'topup', 'amount' => 50000, 'phone' => $c->phone, 'destination' => $c->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $c);
        $this->assertSame(53000, $topup->total);
        app(CustomerCommerce::class)->paid($topup, 'test:mid-topup', $topup->total, now());
        $this->assertSame(150000, $c->fresh()->balance);
        $p = $this->product($s);
        $order = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), $c);
        app(CustomerCommerce::class)->paid($order, 'test:mid-product', $order->total, now());
        PremiumStock::where('product_id', $p->id)->delete();
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame(165000, $c->fresh()->balance);
        $this->assertSame(15000, $order->fresh()->refund_amount);
    }

    public function test_guest_failure_never_credits_a_wallet_even_after_customer_registration(): void
    {
        $s = $this->store();
        $this->gateway($s, 'custom');
        $p = $this->product($s);
        $order = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null);
        $c = $this->customer($s);
        app(CustomerCommerce::class)->paid($order, 'test:guest', $order->total, now());
        PremiumStock::where('product_id', $p->id)->delete();
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertNull($order->fresh()->customer_id);
        $this->assertNull($order->fresh()->refunded_at);
        $this->assertSame(100000, $c->fresh()->balance);
        $this->assertDatabaseCount('customer_ledgers', 0);
    }

    private function remote(Store $s, CatalogProduct $p, int $cost = 8000): SupplierProduct
    {
        $connection = SupplierConnection::create(['store_id' => $s->id, 'kind' => 'digiflazz', 'credentials' => ['username' => 'buyer', 'api_key' => 'fake-key'], 'enabled' => true]);
        $item = SupplierProduct::create(['store_id' => $s->id, 'connection_id' => $connection->id, 'sku' => 'SKU-TEST', 'name' => 'Remote', 'kind' => 'premium', 'service' => 'Aplikasi', 'category' => 'Canva', 'cost' => $cost, 'available' => true, 'observed_at' => now()]);
        ProductMapping::create(['store_id' => $s->id, 'product_id' => $p->id, 'source' => 'digiflazz', 'supplier_product_id' => $item->id, 'source_key' => 'item:'.$item->id, 'active' => true]);

        return $item;
    }

    public function test_pending_and_transport_errors_keep_the_same_attempt_and_never_fallback(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $this->remote($s, $p);
        $count = 0;
        $references = [];
        Http::fake(function ($r) use (&$count, &$references) {
            $references[] = $r['ref_id'];
            $count++;

            return Http::response(['data' => ['ref_id' => $r['ref_id'], 'buyer_sku_code' => $r['buyer_sku_code'], 'customer_no' => $r['customer_no'], 'status' => $count === 1 ? 'Pending' : 'Sukses', 'rc' => $count === 1 ? '03' : '00', 'price' => 8000, 'sn' => 'SN-TEST']]);
        });
        $order = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, PremiumStock::where('status', 'ready')->count());
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame(1, $count);
        $this->travel(61)->seconds();
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame($references[0], $references[1]);
        $this->assertSame(1, PremiumStock::where('status', 'ready')->count());
        $this->assertSame(0, CustomerLedger::where('reason', 'refund')->count());
    }

    public function test_explicit_failed_source_falls_back_to_stock_and_price_increase_fails_instead(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s, 2);
        $item = $this->remote($s, $p);
        Http::fake(fn ($r) => Http::response(['data' => ['ref_id' => $r['ref_id'], 'buyer_sku_code' => $r['buyer_sku_code'], 'customer_no' => $r['customer_no'], 'status' => 'Gagal', 'rc' => '44', 'price' => 8000]]));
        $order = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $other = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        $item->update(['cost' => 16000]);
        app(CustomerFulfillment::class)->run($other);
        $this->assertSame('failed', $other->fresh()->status);
        $this->assertNotNull($other->fresh()->refunded_at);
        $this->assertSame(1, PremiumStock::where('status', 'ready')->count());
        Http::assertSentCount(1);
    }

    public function test_unrecognized_response_is_pending_without_refund(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $this->remote($s, $p);
        Http::fake(fn ($r) => Http::response(['data' => ['ref_id' => $r['ref_id'], 'buyer_sku_code' => $r['buyer_sku_code'], 'customer_no' => $r['customer_no'], 'status' => 'Sukses', 'rc' => '00', 'price' => 8000, 'sn' => 'UNTRUSTED-ERROR-BODY']], 503));
        $order = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->refunded_at);
        $this->assertSame(1, PremiumStock::where('status', 'ready')->count());
    }

    public function test_midtrans_notification_requires_signature_and_authoritative_status(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $this->gateway($s);
        $p = $this->product($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), $c);
        $body = ['order_id' => $o->number, 'status_code' => '200', 'gross_amount' => $o->total.'.00', 'transaction_status' => 'settlement'];
        $body['signature_key'] = hash('sha512', $body['order_id'].$body['status_code'].$body['gross_amount'].'test-only-server-key');
        $url = '/api/commerce/midtrans/'.$s->id;
        $this->postJson($url, [...$body, 'signature_key' => str_repeat('0', 128)])->assertUnauthorized();
        $settled = false;
        Http::fake(function () use ($body, &$settled) {
            return Http::response([...$body, 'transaction_status' => $settled ? 'settlement' : 'pending', 'transaction_id' => 'tx-test', 'currency' => 'IDR', 'fraud_status' => 'accept', 'settlement_time' => now()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s')]);
        });
        $this->postJson($url, $body)->assertOk();
        $this->assertSame('unpaid', $o->fresh()->payment_status);
        $settled = true;
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();
        $this->assertSame('paid', $o->fresh()->payment_status);
        $this->assertDatabaseCount('commerce_receipts', 1);
    }

    public function test_custom_forwarder_is_authenticated_scoped_and_replay_safe(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $this->gateway($s, 'custom');
        $o = app(CustomerCommerce::class)->begin($s, ['purpose' => 'topup', 'amount' => 50000, 'phone' => $c->phone, 'destination' => $c->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $c);
        $body = ['event_id' => 'notification-test', 'application_package' => 'id.dana', 'title' => 'Pembayaran masuk', 'text' => 'Rp'.number_format($o->total, 0, ',', '.').' pembayaran diterima.', 'occurred_at' => now()->toIso8601String()];
        $url = '/api/commerce/forward/'.$s->id;
        $this->postJson($url, $body)->assertUnauthorized();
        $this->withToken(str_repeat('f', 40))->postJson($url, [...$body, 'application_package' => 'untrusted.app'])->assertUnprocessable();
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();
        $this->assertSame(150000, $c->fresh()->balance);
        $this->assertDatabaseCount('commerce_receipts', 1);
    }

    public function test_guest_receipt_and_logged_in_customer_are_isolated_by_store(): void
    {
        $s = $this->store();
        $other = $this->store();
        $this->gateway($s);
        $p = $this->product($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null);
        $path = '/orders/'.$o->id;
        $this->get('http://'.$s->slug.'.localhost'.$path)->assertNotFound();
        $token = app(CustomerCommerce::class)->token($o);
        $this->get('http://'.$s->slug.'.localhost'.$path.'?access='.$token)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion()])->get('http://'.$s->slug.'.localhost'.$path.'?access='.$token)->assertOk()->assertJsonPath('component', 'Storefront/Order');
        $this->get('http://'.$other->slug.'.localhost'.$path.'?access='.$token)->assertNotFound();
        $c = $this->customer($other);
        $this->actingAs($c, 'customer')->get('http://'.$s->slug.'.localhost/account')->assertRedirect('/account/login');
    }

    public function test_guests_can_be_disabled_and_customer_cannot_spend_another_stores_wallet(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        app(CustomerCommerce::class)->settings($s)->update(['allow_guest' => false]);
        foreach ([null, $this->customer($this->store())] as $customer) {
            try {
                app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), $customer);
                $this->fail('Must reject');
            } catch (ValidationException) {
            }
        }
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertDatabaseCount('customer_ledgers', 0);
    }

    public function test_order_lookup_is_public_and_returns_status_without_private_receipt_fields(): void
    {
        $store = $this->store();
        $this->gateway($store);
        $product = $this->product($store);
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product, ['payment' => 'gateway']), null);
        $order->update(['status' => 'success', 'payment_status' => 'paid', 'result' => 'private-premium-password', 'destination' => 'private-destination']);
        $host = 'http://'.$store->slug.'.localhost';
        $this->get($host.'/cek-pesanan')->assertOk()->assertInertia(fn (Page $page) => $page
            ->component('Storefront/OrderLookup')->where('receipt', null)->where('searched', false));
        $this->get($host.'/cek-pesanan?number='.strtolower($order->number))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Page $page) => $page->where('receipt.number', $order->number)->where('receipt.status', 'success')
                ->where('receipt.total', 15000)->where('lookupError', null)->missing('receipt.result')->missing('receipt.phone')
                ->missing('receipt.destination')->missing('receipt.id')->missing('receipt.access')->missing('receipt.url'));
        $this->assertGuest('customer');
    }

    public function test_order_lookup_handles_invalid_input_and_never_finds_another_stores_order(): void
    {
        $store = $this->store();
        $other = $this->store();
        $this->gateway($store);
        $order = app(CustomerCommerce::class)->begin($store, $this->input($this->product($store), ['payment' => 'gateway']), null);
        $host = 'http://'.$other->slug.'.localhost';
        $this->get($host.'/cek-pesanan?number='.$order->number)->assertOk()->assertInertia(fn (Page $page) => $page
            ->where('receipt', null)->where('searched', true)->where('lookupError', 'Pesanan tidak ditemukan di toko ini. Periksa kembali nomor pada bukti pesanan Anda.'));
        foreach (['number[]=invalid', 'number=%3Cscript%3E', 'number='.str_repeat('A', 100)] as $query) {
            $this->get($host.'/cek-pesanan?'.$query)->assertOk()->assertInertia(fn (Page $page) => $page
                ->where('receipt', null)->where('lookupError', 'Masukkan nomor pesanan yang valid.'));
        }
        $this->get('http://localhost/cek-pesanan')->assertNotFound();
    }

    public function test_guest_can_open_full_receipt_with_matching_checkout_phone_without_creating_an_account(): void
    {
        $store = $this->store();
        $this->gateway($store);
        $commerce = app(CustomerCommerce::class);
        $order = $commerce->begin($store, $this->input($this->product($store), ['payment' => 'gateway']), null);
        $url = 'http://'.$store->slug.'.localhost/cek-pesanan/detail';
        $this->post($url, ['number' => $order->number, 'phone' => '089999999999'])->assertSessionHasErrors('phone');
        $this->post($url, ['number' => strtolower($order->number), 'phone' => '081234567890'])->assertStatus(303)
            ->assertRedirect('/orders/'.$order->id.'?access='.$commerce->token($order))->assertHeader('Cache-Control', 'no-store, private');
        $other = $this->store();
        $this->post('http://'.$other->slug.'.localhost/cek-pesanan/detail', ['number' => $order->number, 'phone' => '081234567890'])->assertSessionHasErrors('phone');
        $this->assertGuest('customer');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_public_order_checks_remain_available_after_store_stops_accepting_new_orders(): void
    {
        $store = $this->store();
        $this->gateway($store);
        $order = app(CustomerCommerce::class)->begin($store, $this->input($this->product($store), ['payment' => 'gateway']), null);
        $store->update(['status' => 'expired', 'expires_at' => now()->subDays(10)]);
        $this->get('http://'.$store->slug.'.localhost/cek-pesanan?number='.$order->number)->assertOk()
            ->assertInertia(fn (Page $page) => $page->where('receipt.number', $order->number));
    }

    public function test_staff_permissions_contacts_and_cross_store_reads_are_enforced(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        $staff = StoreStaff::create(['store_id' => $s->id, 'username' => 'reader', 'email' => 'staff@example.test', 'name' => 'Staff', 'password' => 'test-password-only', 'permissions' => ['orders.view', 'customers.view'], 'active' => true]);
        $this->actingAs($staff, 'staff')->get('/stores/'.$s->id.'/orders')->assertOk();
        $this->get('/stores/'.$s->id.'/orders/'.$o->id)->assertInertia(fn (Page $page) => $page->missing('order.phone')->missing('order.result')->where('canReveal', false));
        $this->get('/stores/'.$s->id.'/customers')->assertInertia(fn (Page $page) => $page->missing('items.0.phone'));
        $this->postJson('/stores/'.$s->id.'/orders/'.$o->id.'/result')->assertForbidden();
        $this->get('/stores/'.$this->store()->id.'/orders')->assertForbidden();
    }

    public function test_close_and_cash_settlement_require_stopping_and_zero_obligations(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $this->actingAs($s->owner);
        $base = '/stores/'.$s->id;
        $this->post($base.'/customers/'.$c->id.'/settle', ['amount' => 100000, 'note' => 'Bukti transfer tes', 'reference' => (string) Str::uuid()])->assertSessionHasErrors('commerce');
        $this->post($base.'/closure', ['action' => 'closing', 'closing' => true, 'finalize' => false])->assertSessionHasNoErrors();
        $this->post($base.'/closure', ['action' => 'closing', 'closing' => true, 'finalize' => true])->assertSessionHasErrors('commerce');
        $data = ['amount' => 100000, 'note' => 'Bukti transfer tes', 'reference' => (string) Str::uuid()];
        $this->post($base.'/customers/'.$c->id.'/settle', $data)->assertSessionHasNoErrors();
        $this->post($base.'/customers/'.$c->id.'/settle', $data)->assertSessionHasNoErrors();
        $this->assertSame(0, $c->fresh()->balance);
        $this->assertDatabaseCount('customer_ledgers', 1);
        $this->post($base.'/closure', ['action' => 'closing', 'closing' => true, 'finalize' => true])->assertSessionHasNoErrors();
        $this->assertSame('suspended_manual', $s->fresh()->status);
    }

    public function test_store_closure_has_an_owner_only_page_and_endpoint_separate_from_payments(): void
    {
        $store = $this->store();
        $this->customer($store);
        $url = '/stores/'.$store->id.'/closure';
        $this->actingAs($store->owner)->get($url)->assertOk()->assertInertia(fn (Page $page) => $page
            ->component('Owner/StoreClosure')->where('closing', false)->where('obligations.balance', 100000)
            ->where('obligations.orders', 0)->where('obligations.guests', 0));
        $this->post('/stores/'.$store->id.'/payments', ['action' => 'closing', 'closing' => true])->assertSessionHasErrors('action');
        $this->assertFalse(app(CustomerCommerce::class)->settings($store)->fresh()->closing);
        $other = $this->store();
        $this->actingAs($other->owner)->get($url)->assertForbidden();
        $this->post($url, ['closing' => true])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Pembayaran', 'username' => 'closure-staff', 'email' => 'closure@test.invalid', 'password' => 'test-password-123', 'active' => true, 'permissions' => ['web.manage', 'payments.view', 'payments.policy']]);
        $this->actingAs($staff, 'staff')->get($url)->assertForbidden();
        $this->post($url, ['closing' => true])->assertForbidden();
        $this->assertFalse(app(CustomerCommerce::class)->settings($store)->fresh()->closing);
    }

    public function test_custom_license_and_snapshot_secrets_are_not_exposed(): void
    {
        $s = $this->store();
        $this->actingAs($s->owner)->post('/stores/'.$s->id.'/payments', ['action' => 'connection', 'gateway' => 'custom', 'sandbox' => true, 'qris_payload' => $this->qris(), 'application_package' => 'id.dana'])->assertSessionHasErrors('commerce');
        $this->gateway($s);
        $this->get('/stores/'.$s->id.'/payments')->assertOk()->assertInertia(fn (Page $page) => $page->missing('settings.credentials')->where('configured.midtrans', true));
        $p = $this->product($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null);
        $this->assertStringNotContainsString('test-only-server-key', json_encode($o));
        $this->assertStringNotContainsString('test-only-server-key', DB::table('customer_orders')->value('payment_credentials'));
    }

    public function test_pending_notification_is_sent_once_and_finished_order_sends_another_event(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $this->remote($s, $p);
        Http::fake(fn ($r) => Http::response(['data' => ['ref_id' => $r['ref_id'], 'buyer_sku_code' => $r['buyer_sku_code'], 'customer_no' => $r['customer_no'], 'status' => 'Pending', 'rc' => '03', 'price' => 8000]]));
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        app(CustomerFulfillment::class)->run($o);
        $this->travel(6)->minutes();
        config(['services.hanshop.url' => null]);
        $this->artisan('digitaltoko:commerce', ['--limit' => 10])->assertSuccessful();
        $this->artisan('digitaltoko:commerce', ['--limit' => 10])->assertSuccessful();
        $this->assertSame(1, CustomerNotice::where('event', 'order:'.$o->id.':pending')->count());
        $this->assertNull($o->fresh()->refunded_at);
    }

    public function test_postpaid_inquiry_freezes_bill_and_uncertain_payment_only_checks_status(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s, 0);
        $p->update(['kind' => 'postpaid', 'sale_price' => 3000]);
        $item = $this->remote($s, $p, 2500);
        $item->update(['kind' => 'postpaid']);
        Http::fake(function ($r) {
            $command = $r['commands'];
            $status = $command === 'pay-pasca' ? 'Pending' : 'Sukses';

            return Http::response(['data' => ['ref_id' => $r['ref_id'], 'buyer_sku_code' => $r['buyer_sku_code'], 'customer_no' => $r['customer_no'], 'status' => $status, 'rc' => $status === 'Pending' ? '03' : '00', 'price' => 52000, 'customer_name' => 'Nama Dummy', 'periode' => '202610', 'sn' => 'BILL-SN']]);
        });
        $service = app(CustomerInquiryService::class);
        $inquiry = $service->quote($s, $p->id, '12345678', $c->phone, 'inquiry-test-key');
        $this->assertSame(52500, $inquiry->amount);
        $this->assertSame($inquiry->id, $service->quote($s, $p->id, '12345678', $c->phone, 'inquiry-test-key')->id);
        $input = $this->input($p, ['destination' => '12345678', 'inquiry_id' => $inquiry->id, 'expected_price' => 52500]);
        $o = app(CustomerCommerce::class)->begin($s, $input, $c);
        app(CustomerFulfillment::class)->run($o);
        $this->assertSame('pending', $o->fresh()->status);
        $this->assertSame(47500, $c->fresh()->balance);
        $this->travel(61)->seconds();
        app(CustomerFulfillment::class)->run($o);
        $this->assertSame('success', $o->fresh()->status);
        Http::assertSentCount(4);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertSame(['inq-pasca', 'inq-pasca', 'pay-pasca', 'status-pasca'], $requests->map(fn ($r) => $r['commands'])->all());
        $this->assertCount(1, $requests->map(fn ($r) => $r['ref_id'])->unique());
        try {
            app(CustomerCommerce::class)->begin($s, [...$input, 'idempotency_key' => (string) Str::uuid()], $c);
            $this->fail('Inquiry must be consumed once');
        } catch (ValidationException) {
        }
    }

    public function test_old_pending_digiflazz_transaction_never_creates_another_purchase(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $this->remote($s, $p);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        $o->attempts()->where('source', 'digiflazz')->update(['created_at' => now()->subDays(90)]);
        app(CustomerFulfillment::class)->run($o);
        $this->assertSame('review', $o->fresh()->status);
        $this->assertNull($o->fresh()->refunded_at);
        Http::assertNothingSent();
    }

    public function test_customer_otp_email_login_and_store_bound_challenges(): void
    {
        $s = $this->store();
        $other = $this->store();
        $otp = null;
        $this->mock(HanshopIdentity::class, function ($mock) use (&$otp) {
            $mock->shouldReceive('call')->once()->withArgs(function ($path, $data) use (&$otp) {
                $otp = $data['otp'];

                return $path === 'otp';
            })->andReturn([]);
        });
        $base = 'http://'.$s->slug.'.localhost';
        $this->post($base.'/account/otp', ['phone' => '081234567890', 'name' => 'Customer Dummy'])->assertSessionHasNoErrors();
        $this->post('http://'.$other->slug.'.localhost/account/verify', ['otp' => $otp])->assertSessionHasErrors('otp');
        $this->post($base.'/account/verify', ['otp' => $otp])->assertRedirect('/account');
        $customer = Customer::where('store_id', $s->id)->firstOrFail();
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertSame(0, $customer->balance);
        $this->post($base.'/account/verify', ['otp' => $otp])->assertSessionHasErrors('otp');
        Mail::fake();
        $this->post($base.'/account/email/otp', ['email' => 'buyer@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $mail = Mail::sent(OwnerOtpMail::class)->first();
        $this->post($base.'/account/email/verify', ['otp' => $mail->code, 'password' => 'password-test-only', 'password_confirmation' => 'password-test-only'])->assertSessionHasNoErrors();
        $this->post($base.'/account/logout');
        $this->post($base.'/account/password', ['email' => 'buyer@example.test', 'password' => 'password-test-only'])->assertRedirect('/account');
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->post('http://'.$other->slug.'.localhost/account/password', ['email' => 'buyer@example.test', 'password' => 'password-test-only'])->assertSessionHasErrors('commerce');
    }

    public function test_custom_module_payment_activates_once_without_wallet_credit(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        DB::table('commerce_options')->insert(['id' => 'custom_qris_price', 'value' => 100000]);
        $paid = false;
        $this->mock(HanshopIdentity::class, function ($mock) use (&$paid) {
            $mock->shouldReceive('call')->andReturnUsing(function ($path, $data) use (&$paid) {
                return ['invoice' => [...$data, 'total' => $data['amount'] + 123, 'status' => $paid ? 'PAID' : 'PENDING', 'qr_url' => 'https://example.test/qr', 'expires_at' => now()->addMinutes(15)->toIso8601String(), 'paid_at' => $paid ? now()->toIso8601String() : null]];
            });
        });
        $service = app(CommerceModules::class);
        $invoice = $service->buy($s);
        $this->assertSame('pending', $invoice->status);
        $this->assertSame($invoice->id, $service->buy($s)->id);
        $paid = true;
        $service->sync($invoice);
        $service->sync($invoice->fresh());
        $this->assertNotNull(app(CustomerCommerce::class)->settings($s)->custom_licensed_at);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(100000, $c->fresh()->balance);
        $this->assertDatabaseCount('customer_ledgers', 0);
    }

    public function test_bot_commerce_requires_worker_identity_and_uses_authenticated_customer_wallet(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $token = Str::random(64);
        $s->update(['worker_token_hash' => hash('sha256', $token)]);
        $data = ['action' => 'buy', 'phone' => '6281234567890', 'product_id' => $p->id, 'destination' => '6281234567890', 'payment' => 'wallet', 'idempotency_key' => 'bot-test-123'];
        $this->postJson('/api/internal/bot/commerce', $data)->assertUnauthorized();
        $c = $this->customer($s);
        $response = $this->withToken($token)->postJson('/api/internal/bot/commerce', $data)->assertOk()->assertJsonPath('data.order.amount', 15000);
        $this->postJson('/api/internal/bot/commerce', $data)->assertJsonPath('data.order.id', $response->json('data.order.id'));
        $this->assertSame(85000, $c->fresh()->balance);
        $this->assertDatabaseCount('customer_orders', 1);
    }

    public function test_gateway_creation_is_idempotent_and_qris_identity_does_not_change_with_amount(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null);
        Http::fake(['api.sandbox.midtrans.com/*' => Http::response(['status_code' => '201', 'order_id' => $o->number, 'gross_amount' => $o->total.'.00', 'transaction_id' => 'test-qris-id'])]);
        app(CustomerPayments::class)->start($o);
        app(CustomerPayments::class)->start($o->fresh());
        Http::assertSentCount(1);
        $this->assertSame('https://api.sandbox.midtrans.com/v2/qris/test-qris-id/qr-code', $o->fresh()->qr_image);
        $qris = app(CustomQris::class);
        $dynamic = $qris->amount($this->qris(), 50001);
        $this->assertSame($qris->identity($this->qris()), $qris->identity($dynamic));
        try {
            $qris->identity(substr($this->qris(), 0, -4).'0000');
            $this->fail('CRC expected');
        } catch (ValidationException) {
        }
    }

    public function test_paid_review_can_be_settled_once_by_owner_only_before_supplier_dispatch(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $this->gateway($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), $c);
        $this->travel(16)->minutes();
        app(CustomerCommerce::class)->paid($o, 'late-payment', $o->total, now());
        $this->assertSame('review', $o->fresh()->status);
        $this->actingAs($s->owner)->post('/stores/'.$s->id.'/orders/'.$o->id, ['action' => 'review', 'note' => 'Dana masuk sudah diperiksa.'])->assertSessionHasNoErrors();
        $this->assertSame('failed', $o->fresh()->status);
        $this->assertSame(115000, $c->fresh()->balance);
        $this->post('/stores/'.$s->id.'/orders/'.$o->id, ['action' => 'review', 'note' => 'Ulang pemeriksaan'])->assertSessionHasErrors('commerce');
        $this->assertSame(115000, $c->fresh()->balance);
        $o2 = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        $o2->update(['status' => 'review']);
        $o2->attempts()->update(['last_checked_at' => now(), 'status' => 'pending']);
        $this->post('/stores/'.$s->id.'/orders/'.$o2->id, ['action' => 'review', 'note' => 'Tidak boleh memaksa pending'])->assertSessionHasErrors('commerce');
        $this->assertNull($o2->fresh()->refunded_at);
    }

    public function test_custom_product_refund_uses_the_policy_captured_before_payment(): void
    {
        $s = $this->store();
        $c = $this->customer($s);
        $p = $this->product($s);
        $this->gateway($s, 'custom');
        $setting = app(CustomerCommerce::class)->settings($s);
        $setting->update(['refund_unique' => true, 'topup_unique' => false]);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), $c);
        $setting->update(['refund_unique' => false]);
        app(CustomerCommerce::class)->paid($o, 'qris-product', $o->total, now());
        PremiumStock::where('product_id', $p->id)->delete();
        app(CustomerFulfillment::class)->run($o);
        app(CustomerFulfillment::class)->run($o->fresh());
        $this->assertSame('failed', $o->fresh()->status);
        $this->assertSame(115000 + $o->unique_code, $c->fresh()->balance);
        $this->assertSame(1, CustomerLedger::where('reason', 'refund')->count());
    }
}
