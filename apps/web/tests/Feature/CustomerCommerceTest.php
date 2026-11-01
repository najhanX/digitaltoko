<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerOrder;
use App\Mail\CustomerOtpMail;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\PremiumStock;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\BotManager;
use App\Services\CommerceModules;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerInquiryService;
use App\Services\CustomerPayments;
use App\Services\CustomQris;
use App\Services\GuestCheckout;
use App\Services\QrisNotificationParser;
use App\Services\StoreCatalog;
use App\Support\HanshopIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Page;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerCommerceTest extends TestCase
{
    use RefreshDatabase;

    private function confirmation(CustomerOrder $order, array $extra = []): array
    {
        return [...['action' => 'confirm_payment', 'received_amount' => $order->total, 'paid_at' => now()->toIso8601String(), 'reference' => 'BANK-TEST-001', 'note' => 'Sudah diperiksa pada mutasi merchant', 'confirmed' => true], ...$extra];
    }

    public function test_qris_parser_can_be_saved_and_tested_by_authorized_staff_without_changing_payments(): void
    {
        $store = $this->store();
        $this->gateway($store, 'custom');
        $order = app(CustomerCommerce::class)->begin($store, $this->input($this->product($store), ['payment' => 'gateway']), null);
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Kasir', 'email' => 'parser-staff@example.test', 'username' => 'parser-staff', 'password' => 'password-test', 'active' => true, 'permissions' => ['payments.view']]);
        $config = app(QrisNotificationParser::class)->defaults();
        $url = '/stores/'.$store->id.'/payments';
        $data = ['parser' => $config, 'text' => 'Rp292 diterima DANA Bisnis.'];
        $this->actingAs($staff, 'staff')->postJson($url.'/parser-test', $data)->assertForbidden();
        $staff->update(['permissions' => ['payments.parsers']]);
        $this->actingAs($staff->fresh(), 'staff')->get($url)->assertOk()->assertInertia(fn (Page $page) => $page->where('canParser', true)->where('canConnect', false)->missing('settings.credentials'));
        $this->post($url, ['action' => 'parser', 'parser' => $config])->assertSessionHasNoErrors();
        $credentials = app(CustomerCommerce::class)->settings($store)->fresh()->credentials;
        $this->assertSame($config, $credentials['notification_parser']);
        $this->assertSame(str_repeat('f', 40), $credentials['forward_token']);
        $this->postJson($url.'/parser-test', $data)->assertOk()->assertJsonPath('matched', true)->assertJsonPath('amount', 292);
        $this->postJson('/stores/'.$this->store()->id.'/payments/parser-test', $data)->assertForbidden();
        $this->post($url, ['action' => 'parser', 'parser' => [...$config, 'rules' => []]])->assertSessionHasErrors('rules');
        $this->postJson($url, ['action' => 'parser', 'parser' => 'wrong-type'])->assertUnprocessable();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('commerce_receipts', 0);
        Queue::assertNothingPushed();
    }

    public function test_saved_qris_parser_updates_pending_invoice_matching_but_does_not_fall_back_on_unknown_messages(): void
    {
        $store = $this->store();
        $this->gateway($store, 'custom');
        $order = app(CustomerCommerce::class)->begin($store, $this->input($this->product($store), ['payment' => 'gateway']), null);
        $config = [...app(QrisNotificationParser::class)->defaults(), 'rules' => [
            ['name' => 'Bank custom', 'active' => true, 'template' => 'Dana masuk Rp[nominal], ref [referensi]. Saldo Rp[abaikan].'],
        ]];
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/payments', ['action' => 'parser', 'parser' => $config])->assertSessionHasNoErrors();
        $body = ['event_id' => 'bank-event-1', 'application_package' => 'id.dana', 'text' => 'Rp'.number_format($order->total, 0, ',', '.').' pembayaran diterima.', 'occurred_at' => now()->toIso8601String()];
        $url = '/api/commerce/forward/'.$store->id;
        $this->withToken(str_repeat('f', 40))->postJson($url, $body)->assertUnprocessable();
        $body['text'] = 'Dana masuk Rp'.number_format($order->total, 0, ',', '.').', ref BANK-REF1. Saldo Rp500.000.';
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, [...$body, 'event_id' => 'bank-event-duplicate'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('commerce_receipts', 1);
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
    }

    public function test_dana_business_preset_without_title_matches_payment_once_and_rejects_promos(): void
    {
        $s = $this->store();
        $this->gateway($s, 'custom');
        $order = app(CustomerCommerce::class)->begin($s, $this->input($this->product($s), ['payment' => 'gateway']), null);
        $body = ['event_id' => 'dana-business-receipt', 'application_package' => 'id.dana', 'text' => 'Rp'.number_format($order->total, 0, ',', '.').' diterima DANA Bisnis.', 'occurred_at' => now()->toIso8601String()];
        $url = '/api/commerce/forward/'.$s->id;
        $this->withToken(str_repeat('f', 40));
        foreach (['Promo terbesar di DANA 11.11, hadiah miliaran!', 'Rp292 berhasil dikirim.', 'Rp292 diterima DANA Bisnis. Promo hadiah!', 'Rp292 diterima DANA Bisnis. Gagal.'] as $index => $text) {
            $this->postJson($url, [...$body, 'event_id' => 'rejected-'.$index, 'text' => $text])->assertUnprocessable();
        }
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->postJson($url, [...$body, 'text' => 'Rp1 diterima DANA Bisnis.'])->assertUnprocessable();
        $this->postJson($url, [...$body, 'application_package' => 'com.other.app'])->assertUnprocessable();
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('commerce_receipts', 1);
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
    }

    public function test_custom_manual_payment_is_audited_and_callback_replay_does_not_dispatch_twice(): void
    {
        $s = $this->store();
        $this->gateway($s, 'custom');
        $order = app(CustomerCommerce::class)->begin($s, $this->input($this->product($s), ['payment' => 'gateway']), null);
        $url = '/stores/'.$s->id.'/orders/'.$order->id;
        $data = $this->confirmation($order);
        $this->actingAs($s->owner)->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('owner:'.$s->owner_id, $order->fresh()->snapshot['payment_confirmation']['actor']);
        $this->assertDatabaseCount('commerce_receipts', 1);
        $this->withToken(str_repeat('f', 40))->postJson('/api/commerce/forward/'.$s->id, ['event_id' => 'late-notification', 'application_package' => 'id.dana', 'text' => 'Rp'.number_format($order->total, 0, ',', '.').' pembayaran diterima', 'occurred_at' => now()->toIso8601String()])->assertOk();
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
        $this->get($url)->assertInertia(fn (Page $page) => $page->where('canConfirmPayment', false)->where('paymentConfirmation.reference', 'BANK-TEST-001'));
        $this->assertArrayNotHasKey('payment_confirmation', app(CustomerCommerce::class)->publicData($order->fresh()));
    }

    public function test_custom_manual_payment_requires_distinct_permission_and_store_ownership(): void
    {
        $s = $this->store();
        $this->gateway($s, 'custom');
        $order = app(CustomerCommerce::class)->begin($s, $this->input($this->product($s), ['payment' => 'gateway']), null);
        $staff = StoreStaff::create(['store_id' => $s->id, 'name' => 'Kasir', 'email' => 'cashier@example.test', 'username' => 'cashier', 'password' => 'password-test', 'active' => true, 'permissions' => ['orders.manage']]);
        $url = '/stores/'.$s->id.'/orders/'.$order->id;
        $this->actingAs($staff, 'staff')->post($url, $this->confirmation($order))->assertForbidden();
        $staff->update(['permissions' => ['payments.confirm']]);
        $this->actingAs($staff->fresh(), 'staff')->get($url)->assertOk();
        $other = $this->store();
        $this->post('/stores/'.$other->id.'/orders/'.$order->id, $this->confirmation($order))->assertForbidden();
        $this->post($url, $this->confirmation($order))->assertSessionHasNoErrors();
        $this->assertSame('staff:'.$staff->id, $order->fresh()->snapshot['payment_confirmation']['actor']);
    }

    public function test_manual_payment_rejects_midtrans_wrong_amount_future_time_and_missing_acknowledgement(): void
    {
        $s = $this->store();
        $this->gateway($s, 'custom');
        $order = app(CustomerCommerce::class)->begin($s, $this->input($this->product($s), ['payment' => 'gateway']), null);
        $url = '/stores/'.$s->id.'/orders/'.$order->id;
        $this->actingAs($s->owner)->post($url, $this->confirmation($order, ['received_amount' => $order->total - 1]))->assertSessionHasErrors();
        $this->post($url, $this->confirmation($order, ['paid_at' => now()->addHour()->toIso8601String()]))->assertSessionHasErrors();
        $this->post($url, $this->confirmation($order, ['confirmed' => false]))->assertSessionHasErrors('confirmed');
        $order->update(['gateway' => 'midtrans']);
        $this->post($url, $this->confirmation($order))->assertSessionHasErrors();
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertDatabaseCount('commerce_receipts', 0);
    }

    public function test_reused_manual_receipt_is_rejected_and_expired_payment_enters_review(): void
    {
        $s = $this->store();
        $this->gateway($s, 'custom');
        $p = $this->product($s, 2);
        $first = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null);
        $second = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null);
        $this->actingAs($s->owner)->post('/stores/'.$s->id.'/orders/'.$first->id, $this->confirmation($first))->assertSessionHasNoErrors();
        $this->post('/stores/'.$s->id.'/orders/'.$second->id, $this->confirmation($second))->assertSessionHasErrors();
        $this->assertSame('unpaid', $second->fresh()->payment_status);
        $this->assertArrayNotHasKey('payment_confirmation', $second->fresh()->snapshot);
        $second->update(['status' => 'expired']);
        $this->post('/stores/'.$s->id.'/orders/'.$second->id, $this->confirmation($second, ['reference' => 'BANK-TEST-002']))->assertSessionHasNoErrors();
        $this->assertSame('review', $second->fresh()->status);
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
    }

    public function test_custom_connection_accepts_other_package_or_app_name_and_rejects_wrong_source(): void
    {
        $s = $this->store();
        $this->gateway($s, 'custom');
        $url = '/stores/'.$s->id.'/payments';
        $data = ['action' => 'connection', 'gateway' => 'custom', 'sandbox' => false, 'application_package' => 'com.bank.merchant'];
        $this->actingAs($s->owner)->post($url, $data)->assertSessionHasNoErrors();
        $setting = app(CustomerCommerce::class)->settings($s)->fresh();
        $this->assertSame('com.bank.merchant', $setting->credentials['application_package']);
        $this->post($url, [...$data, 'application_package' => '', 'application_name' => 'Merchant Bank'])->assertSessionHasNoErrors();
        $setting->refresh();
        $order = app(CustomerCommerce::class)->begin($s, $this->input($this->product($s), ['payment' => 'gateway']), null);
        $body = ['event_id' => 'custom-app-name', 'application_package' => 'com.bank.merchant', 'app_name' => 'Other App', 'text' => 'Rp'.number_format($order->total, 0, ',', '.').' pembayaran diterima', 'occurred_at' => now()->toIso8601String()];
        $this->withToken($setting->credentials['forward_token'])->postJson('/api/commerce/forward/'.$s->id, $body)->assertUnprocessable();
        $this->postJson('/api/commerce/forward/'.$s->id, [...$body, 'app_name' => 'merchant bank'])->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_forwarder_download_returns_bundle_and_missing_release_is_not_exposed(): void
    {
        config(['services.forwarder.bundle_path' => __DIR__.'/missing-forwarder.zip']);
        $this->get('/downloads/hansdev-forwarder')->assertNotFound();
        $path = tempnam(sys_get_temp_dir(), 'forwarder-test-');
        file_put_contents($path, 'PK-SYNTHETIC-ZIP-FOR-RESPONSE-TEST');
        try {
            config(['services.forwarder.bundle_path' => $path]);
            foreach (['/downloads/hansdev-forwarder.zip', '/downloads/hansdev-forwarder'] as $url) {
                $response = $this->get($url)->assertOk()
                    ->assertDownload('HansDev-Forwarder.zip')
                    ->assertHeader('Content-Type', 'application/zip')
                    ->assertHeader('Pragma', 'no-cache');
                $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            }
        } finally {
            unlink($path);
        }
    }

    public function test_manual_qris_topup_credits_once_and_cancelled_invoice_cannot_be_confirmed(): void
    {
        $s = $this->store();
        $this->gateway($s, 'custom');
        $customer = $this->customer($s);
        $order = app(CustomerCommerce::class)->begin($s, ['purpose' => 'topup', 'amount' => 50000, 'phone' => $customer->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $customer);
        $url = '/stores/'.$s->id.'/orders/'.$order->id;
        $this->actingAs($s->owner)->post($url, $this->confirmation($order))->assertSessionHasNoErrors();
        $this->post($url, $this->confirmation($order))->assertSessionHasNoErrors();
        $this->assertSame(100000 + $order->credit_amount, $customer->fresh()->balance);
        $this->assertSame(1, CustomerLedger::where('reason', 'topup')->count());
        $other = app(CustomerCommerce::class)->begin($s, $this->input($this->product($s), ['payment' => 'gateway']), null);
        app(CustomerPayments::class)->cancel($other);
        $this->post('/stores/'.$s->id.'/orders/'.$other->id, $this->confirmation($other, ['reference' => 'BANK-CANCELLED']))->assertSessionHasErrors();
        $this->assertSame('unpaid', $other->fresh()->payment_status);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([ProcessCustomerOrder::class]);
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

    public function test_bot_product_quote_exposes_category_input_and_prepares_destination_without_ordering(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $product = $this->product($store);
        $token = str_repeat('q', 40);
        $store->update(['worker_token_hash' => hash('sha256', $token)]);
        $input = ['phone' => $customer->phone, 'action' => 'quote', 'code' => $product->code];
        $this->withToken($token)->postJson('/api/internal/bot/commerce', $input)->assertOk()
            ->assertJsonPath('data.destination_profile.type', 'premium')->assertJsonPath('data.destination', $customer->phone);
        $product->update(['kind' => 'ppob']);
        $store->update(['branding' => ['category_destinations' => [$product->category_id => ['type' => 'premium']]]]);
        $this->postJson('/api/internal/bot/commerce', $input)->assertOk()->assertJsonPath('data.destination_profile.type', 'other')->assertJsonMissingPath('data.destination');
        $this->postJson('/api/internal/bot/commerce', [...$input, 'destination_id' => '12345678'])->assertOk()->assertJsonPath('data.destination', '12345678');
        $store->update(['branding' => ['category_destinations' => [$product->category_id => ['type' => 'phone']]]]);
        $this->postJson('/api/internal/bot/commerce', [...$input, 'destination_id' => '0812 3456 7890'])->assertOk()
            ->assertJsonPath('data.destination_profile.label', 'Nomor HP tujuan')->assertJsonPath('data.destination', '081234567890');
        $this->postJson('/api/internal/bot/commerce', [...$input, 'destination_id' => 'wrong'])->assertUnprocessable();
        $store->update(['branding' => ['category_destinations' => [$product->category_id => ['type' => 'game', 'columns' => '2']], 'category_games' => [$product->category_id => ['game' => 'none', 'destination_format' => 'pipe']]]]);
        $this->postJson('/api/internal/bot/commerce', [...$input, 'destination_id' => '12345678', 'destination_server' => '1234'])->assertOk()
            ->assertJsonPath('data.destination_profile.columns', 2)->assertJsonPath('data.destination', '12345678|1234');
        $this->postJson('/api/internal/bot/commerce', [...$input, 'destination_id' => '12345678'])->assertUnprocessable();
        $this->assertDatabaseCount('customer_orders', 0);
        $this->assertSame(100000, $customer->fresh()->balance);
        Http::assertNothingSent();
    }

    private function gateway(Store $s, string $type = 'midtrans'): void
    {
        app(CustomerCommerce::class)->settings($s)->update(['gateway' => $type, 'credentials' => ['server_key' => 'test-only-server-key', 'qris_payload' => $this->qris(), 'forward_token' => str_repeat('f', 40), 'application_package' => 'id.dana'], 'custom_licensed_at' => $type === 'custom' ? now() : null]);
    }

    public function test_order_auto_refresh_checks_midtrans_and_records_payment_once(): void
    {
        $store = $this->store();
        $this->gateway($store);
        $product = $this->product($store);
        $engine = app(CustomerCommerce::class);
        $order = $engine->begin($store, $this->input($product, ['payment' => 'gateway']), null);
        $order->update(['provider_id' => 'MID-AUTO-1', 'qr_image' => 'data:image/png;base64,dGVzdA==']);
        $paid = false;
        Http::fake(function ($request) use ($order, &$paid) {
            $this->assertSame('GET', $request->method());
            $this->assertStringEndsWith('/'.$order->number.'/status', $request->url());

            return Http::response(['status_code' => $paid ? '200' : '201', 'order_id' => $order->number, 'gross_amount' => $order->total.'.00', 'currency' => 'IDR', 'transaction_id' => 'MID-AUTO-1', 'transaction_status' => $paid ? 'settlement' : 'pending', 'settlement_time' => now()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s')]);
        });
        $url = $engine->url($order);
        $this->get($url)->assertOk()->assertInertia(fn (Page $page) => $page->where('order.payment_status', 'unpaid')->where('order.payment_check_failed', false));
        $paid = true;
        $this->get($url)->assertOk();
        Http::assertSentCount(1);
        $this->travel(11)->seconds();
        $this->get($url)->assertOk()->assertInertia(fn (Page $page) => $page->where('order.payment_status', 'paid')->where('order.payment_check_failed', false));
        $this->assertSame('paid', $order->fresh()->payment_status);
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
        $this->get($url)->assertOk();
        Http::assertSentCount(2);
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
        $this->assertDatabaseCount('commerce_receipts', 1);
    }

    public function test_order_payment_poll_recovers_from_midtrans_error_and_rejects_invalid_access(): void
    {
        $store = $this->store();
        $this->gateway($store);
        $product = $this->product($store);
        $engine = app(CustomerCommerce::class);
        $order = $engine->begin($store, $this->input($product, ['payment' => 'gateway']), null);
        $order->update(['provider_id' => 'MID-AUTO-2', 'qr_image' => 'data:image/png;base64,dGVzdA==']);
        $failed = true;
        Http::fake(function () use ($order, &$failed) {
            return $failed ? Http::response([], 503) : Http::response(['status_code' => '200', 'order_id' => $order->number, 'gross_amount' => $order->total.'.00', 'currency' => 'IDR', 'transaction_id' => 'MID-AUTO-2', 'transaction_status' => 'settlement', 'settlement_time' => now()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s')]);
        });
        $url = $engine->url($order);
        $this->get(str_replace($engine->token($order), 'invalid-token', $url))->assertNotFound();
        Http::assertNothingSent();
        $this->get($url)->assertOk()->assertInertia(fn (Page $page) => $page->where('order.payment_status', 'unpaid')->where('order.payment_check_failed', true));
        $this->get($url)->assertOk();
        Http::assertSentCount(1);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $failed = false;
        $this->travel(11)->seconds();
        $this->get($url)->assertOk()->assertInertia(fn (Page $page) => $page->where('order.payment_status', 'paid')->where('order.payment_check_failed', false));
        Http::assertSentCount(2);
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
            app(CustomerCommerce::class)->begin($s, [...$input, 'product_id' => $this->product($s)->id], $c);
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

            return Http::response(['data' => ['ref_id' => $r['ref_id'], 'buyer_sku_code' => strtolower($r['buyer_sku_code']), 'customer_no' => $r['customer_no'], 'status' => $count === 1 ? 'Pending' : 'Sukses', 'rc' => $count === 1 ? '03' : '00', 'price' => 8000, 'sn' => 'SN-TEST']]);
        });
        $order = app(CustomerCommerce::class)->begin($s, $this->input($p), $c);
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(1, PremiumStock::where('status', 'ready')->count());
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame(1, $count);
        $this->travel(61)->seconds();
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame(1, $count);
        app(CustomerFulfillment::class)->retrySupplier($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame($references[0], $references[1]);
        $this->assertSame(1, PremiumStock::where('status', 'ready')->count());
        $this->assertSame(0, CustomerLedger::where('reason', 'refund')->count());
    }

    public function test_unavailable_deleted_and_empty_sources_are_skipped_before_the_first_request(): void
    {
        foreach (['unavailable', 'deleted', 'empty'] as $state) {
            $store = $this->store();
            $customer = $this->customer($store);
            $product = $this->product($store);
            $source = $this->remote($store, $product);
            $order = app(CustomerCommerce::class)->begin($store, $this->input($product), $customer);
            if ($state === 'deleted') {
                $source->delete();
            } else {
                $source->update($state === 'empty' ? ['quantity' => 0] : ['available' => false]);
            }
            app(CustomerFulfillment::class)->run($order);
            $attempt = $order->attempts()->where('source', 'digiflazz')->firstOrFail();
            $this->assertSame('failed', $attempt->status);
            $this->assertNull($attempt->last_checked_at);
            $this->assertStringContainsString('tidak tersedia', $attempt->message);
            $this->assertSame('success', $order->fresh()->status);
            $this->assertSame('sent', PremiumStock::where('product_id', $product->id)->sole()->status);
            $this->assertSame(85000, $customer->fresh()->balance);
        }
        Http::assertNothingSent();
    }

    public function test_a_source_that_disappears_after_dispatch_keeps_pending_without_sending_a_fallback(): void
    {
        $store = $this->store();
        $product = $this->product($store);
        $source = $this->remote($store, $product);
        Http::fake(fn ($request) => Http::response(['data' => ['ref_id' => $request['ref_id'], 'buyer_sku_code' => $request['buyer_sku_code'], 'customer_no' => $request['customer_no'], 'status' => 'Pending', 'rc' => '03', 'price' => 8000]]));
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product), $this->customer($store));
        app(CustomerFulfillment::class)->run($order);
        $source->update(['available' => false]);
        $this->travel(2)->minutes();
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('ready', PremiumStock::where('product_id', $product->id)->sole()->status);
        $this->assertSame(0, CustomerLedger::where('reason', 'refund')->count());
        Http::assertSentCount(1);
    }

    public function test_hanshop_pending_is_rechecked_only_by_explicit_supplier_action(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('t', 40)]);
        $store = $this->store();
        $store->owner->update(['hanshop_user_id' => 'HD-RETRY']);
        $customer = $this->customer($store);
        $product = $this->product($store);
        $this->remote($store, $product);
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product), $customer);
        $attempt = $order->attempts()->where('source', 'digiflazz')->firstOrFail();
        $snapshot = $attempt->request_snapshot;
        $snapshot['id_user'] = 'HD-RETRY';
        $attempt->update(['source' => 'hanshop', 'request_snapshot' => $snapshot]);
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;

            return Http::response(['status' => 'SUCCESS', 'data' => ['reference' => $request['reference'], 'id_user' => $request['id_user'], 'status' => $calls === 1 ? 'PENDING' : 'SUCCESS', 'price' => 8000, 'result' => $calls === 1 ? null : 'HANSHOP-SN']]);
        });
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('pending', $order->fresh()->status);
        $this->travel(2)->minutes();
        app(CustomerFulfillment::class)->run($order->fresh());
        Http::assertSentCount(1);
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/orders/'.$order->id, ['action' => 'retry_supplier'])->assertSessionHasNoErrors();
        Http::assertSentCount(2);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('HANSHOP-SN', $order->fresh()->result);
        $requests = Http::recorded()->values();
        $this->assertSame($requests[0][0]['reference'], $requests[1][0]['reference']);
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

    public function test_guest_session_can_only_create_one_unpaid_order_and_server_rejects_duplicate_keys(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        $this->mock(CustomerPayments::class, function ($mock) {
            $mock->shouldReceive('start')->andReturnNull();
        });
        $url = 'http://'.$s->slug.'.localhost/checkout';
        $this->withSession(['guest_checkout_key' => 'same-browser'])->post($url, $this->input($p, ['payment' => 'gateway']))->assertRedirect();
        $first = CustomerOrder::firstOrFail();
        $this->post($url, $this->input($p, ['payment' => 'gateway']))->assertRedirect('/orders/'.$first->id.'?access='.app(CustomerCommerce::class)->token($first));
        $this->assertDatabaseCount('customer_orders', 1);
        $this->get($url.'?product='.$p->id)->assertInertia(fn (Page $page) => $page->where('guestPendingOrder.number', $first->number));
        $this->withSession(['guest_checkout_key' => 'different-browser'])->post($url, $this->input($p, ['payment' => 'gateway']))->assertRedirect();
        $this->assertDatabaseCount('customer_orders', 2);
        try {
            app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway', 'guest_checkout_hash' => hash('sha256', 'same-browser')]), null);
            $this->fail('Second unpaid guest invoice must be rejected');
        } catch (ValidationException) {
        }
        $first->update(['expires_at' => now()->subMinute()]);
        $this->withSession(['guest_checkout_key' => 'same-browser'])->post($url, $this->input($p, ['payment' => 'gateway']))->assertRedirect();
        $this->assertDatabaseCount('customer_orders', 3);
        $customer = $this->customer($s);
        for ($i = 0; $i < 2; $i++) {
            $logged = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway', 'guest_checkout_hash' => hash('sha256', 'same-browser')]), $customer);
            $this->assertNull($logged->guest_checkout_hash);
        }
        $this->assertDatabaseCount('customer_orders', 5);
    }

    public function test_guest_cancellation_requires_same_session_and_frees_slot_without_reusing_unique_amount(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s, 'custom');
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway', 'guest_checkout_hash' => hash('sha256', 'browser-a')]), null)->fresh();
        $url = 'http://'.$s->slug.'.localhost/orders/'.$o->id.'/cancel?access='.app(CustomerCommerce::class)->token($o);
        $this->withSession(['guest_checkout_key' => 'browser-b'])->post($url)->assertForbidden();
        $this->withSession(['guest_checkout_key' => 'browser-a'])->post($url)->assertSessionHasNoErrors();
        $this->assertSame('expired', $o->fresh()->status);
        $this->assertTrue($o->fresh()->snapshot['cancelled_by_customer']);
        $this->assertSame(1, CustomerNotice::where('event', 'order:'.$o->id.':expired:cancelled')->count());
        $this->assertNotNull($o->fresh()->active_amount_key);
        $new = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway', 'guest_checkout_hash' => hash('sha256', 'browser-a')]), null);
        $this->assertNotSame($o->total, $new->total);
        $this->assertNull(app(GuestCheckout::class)->pending($this->store(), hash('sha256', 'browser-a')));
    }

    public function test_expiry_from_scheduler_and_midtrans_each_create_one_final_notice(): void
    {
        Queue::fake();
        $store = $this->store();
        $product = $this->product($store);
        $this->gateway($store, 'custom');
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product, ['payment' => 'gateway']), null)->fresh();
        $this->travel(16)->minutes();
        $this->artisan('digitaltoko:commerce')->assertSuccessful();
        $this->artisan('digitaltoko:commerce')->assertSuccessful();
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame(1, CustomerNotice::where('event', 'order:'.$order->id.':expired')->count());
        $this->travelBack();
        $this->gateway($store);
        $remote = app(CustomerCommerce::class)->begin($store, $this->input($product, ['payment' => 'gateway']), null)->fresh();
        Http::fake(['*/status' => Http::response(['order_id' => $remote->number, 'gross_amount' => $remote->total.'.00', 'transaction_id' => 'remote-expired', 'status_code' => '407', 'transaction_status' => 'expire'])]);
        app(CustomerPayments::class)->refresh($remote);
        app(CustomerPayments::class)->refresh($remote);
        $this->assertSame(1, CustomerNotice::where('event', 'order:'.$remote->id.':expired')->count());
    }

    public function test_owner_cancellation_enqueues_one_final_notice_without_changing_balance_or_sending_to_supplier(): void
    {
        $store = $this->store();
        $product = $this->product($store);
        $this->gateway($store, 'custom');
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product, ['payment' => 'gateway']), null)->fresh();
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/orders/'.$order->id, ['action' => 'cancel'])->assertRedirect()->assertSessionHasNoErrors();
        $event = 'order:'.$order->id.':expired:cancelled';
        $notice = CustomerNotice::where('event', $event)->sole();
        $this->assertSame($order->phone, $notice->phone);
        $this->assertStringContainsString('Pesanan dibatalkan', $notice->message);
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        app(CustomerPayments::class)->cancel($order, $store->owner_id);
        $this->assertSame(1, CustomerNotice::where('event', $event)->count());
        $this->assertDatabaseCount('customer_ledgers', 0);
        Http::assertNothingSent();
    }

    public function test_midtrans_cancellation_confirms_remote_cancel_and_preserves_settled_payments(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null)->fresh();
        $o->update(['provider_id' => 'tx-cancel']);
        $base = ['order_id' => $o->number, 'gross_amount' => $o->total.'.00', 'transaction_id' => 'tx-cancel', 'status_code' => '200'];
        $settledResponse = null;
        Http::fake([
            '*/status' => function () use ($base, &$settledResponse) {
                return Http::response($settledResponse ?? [...$base, 'transaction_status' => 'pending']);
            },
            '*/cancel' => Http::response([...$base, 'transaction_status' => 'cancel']),
        ]);
        app(CustomerPayments::class)->cancel($o);
        $this->assertTrue($o->fresh()->snapshot['cancelled_by_customer']);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/cancel'));
        $other = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null)->fresh();
        $other->update(['provider_id' => 'settled-id']);
        $settledResponse = ['order_id' => $other->number, 'gross_amount' => $other->total.'.00', 'transaction_id' => 'settled-id', 'status_code' => '200', 'transaction_status' => 'settlement', 'settlement_time' => now()->timezone('Asia/Jakarta')->format('Y-m-d H:i:s')];
        try {
            app(CustomerPayments::class)->cancel($other);
            $this->fail('Paid invoice must not be cancelled');
        } catch (ValidationException) {
        }
        $this->assertSame('paid', $other->fresh()->payment_status);
        $this->assertSame('paid', $other->fresh()->status);
    }

    public function test_failed_midtrans_cancellation_does_not_release_guest_slot(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        $key = hash('sha256', 'guest-failure');
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway', 'guest_checkout_hash' => $key]), null)->fresh();
        $o->update(['provider_id' => 'tx-retry']);
        Http::fake([
            '*/status' => Http::response(['order_id' => $o->number, 'gross_amount' => $o->total.'.00', 'transaction_id' => 'tx-retry', 'status_code' => '201', 'transaction_status' => 'pending']),
            '*/cancel' => Http::response(['status_code' => '412'], 200),
            '*/expire' => Http::response(['status_code' => '500'], 500),
        ]);
        try {
            app(CustomerPayments::class)->cancel($o);
            $this->fail('Unconfirmed cancellation must be rejected');
        } catch (ValidationException) {
        }
        $this->assertSame('awaiting_payment', $o->fresh()->status);
        $this->assertSame(0, CustomerNotice::where('event', 'order:'.$o->id.':expired:cancelled')->count());
        $this->assertSame($o->id, app(GuestCheckout::class)->pending($s, $key)->id);
    }

    public function test_midtrans_cancel_server_error_falls_back_to_confirmed_expiry(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null)->fresh();
        $o->update(['provider_id' => 'tx-expire']);
        $base = ['order_id' => $o->number, 'gross_amount' => $o->total.'.00', 'transaction_id' => 'tx-expire'];
        Http::fake([
            '*/status' => Http::response([...$base, 'status_code' => '201', 'transaction_status' => 'pending']),
            '*/cancel' => Http::response(['status_code' => '500', 'status_message' => 'Recovering'], 500),
            '*/expire' => Http::response([...$base, 'status_code' => '407', 'transaction_status' => 'expire']),
        ]);
        app(CustomerPayments::class)->cancel($o);
        $this->assertSame('expired', $o->fresh()->status);
        $this->assertTrue($o->fresh()->snapshot['cancelled_by_customer']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/expire'));
    }

    public function test_ambiguous_cancel_response_is_reconciled_from_authoritative_status(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null)->fresh();
        $o->update(['provider_id' => 'tx-uncertain']);
        $base = ['order_id' => $o->number, 'gross_amount' => $o->total.'.00', 'transaction_id' => 'tx-uncertain'];
        Http::fake([
            '*/status' => Http::sequence()->push([...$base, 'status_code' => '201', 'transaction_status' => 'pending'])->push([...$base, 'status_code' => '202', 'transaction_status' => 'cancel']),
            '*/cancel' => Http::response(['status_code' => '500'], 500),
        ]);
        app(CustomerPayments::class)->cancel($o);
        $this->assertTrue($o->fresh()->snapshot['cancelled_by_customer']);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/expire'));
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

    public function test_payment_expiry_stays_fifteen_minutes_after_creation_when_order_is_updated(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $product = $this->product($store);
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product), $customer);
        $expiry = $order->fresh()->expires_at;
        $this->assertEqualsWithDelta(900, $order->created_at->diffInSeconds($expiry), 1);
        $this->travel(2)->minutes();
        $order->update(['message' => 'Payment prepared', 'provider_id' => 'test-provider']);
        $this->assertSame($expiry->toIso8601String(), $order->fresh()->expires_at->toIso8601String());
        $this->travelBack();
    }

    public function test_panel_partial_refresh_keeps_filters_and_exposes_only_requested_attempt_data_for_authorized_staff(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $product = $this->product($store);
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product), $customer);
        $order->attempts()->firstOrFail()->update(['position' => 1, 'source' => 'otomax', 'reference' => 'TEST-POLL', 'cost' => 10000, 'status' => 'pending', 'request_snapshot' => ['otomax' => ['connection_name' => 'Supplier Test'], 'supplier_response' => ['message' => 'Masih diproses', 'parser_error' => null]]]);
        $staff = StoreStaff::create(['store_id' => $store->id, 'username' => 'pollreader', 'email' => 'pollreader@example.test', 'name' => 'Reader', 'password' => 'test-password-only', 'permissions' => ['orders.view'], 'active' => true]);
        $base = '/stores/'.$store->id.'/orders';
        $this->actingAs($store->owner)->get($base)->assertOk();
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'Owner/Orders', 'X-Inertia-Partial-Data' => 'items,pagination'];
        $this->actingAs($store->owner)->get($base.'?q='.$order->number.'&per_page=5', $headers)->assertOk()->assertJsonCount(1, 'props.items')->assertJsonPath('props.items.0.id', $order->id)->assertJsonMissingPath('props.store');
        $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion(), 'X-Inertia-Partial-Component' => 'Owner/OrderDetail', 'X-Inertia-Partial-Data' => 'attempts'];
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'staff')->get($base.'/'.$order->id, $headers)->assertOk()->assertJsonPath('props.attempts.0.supplier_response.message', 'Masih diproses')->assertJsonMissingPath('props.order')->assertJsonMissingPath('props.attempts.0.request_snapshot');
        $order->attempts()->first()->recordSupplierResponse('Transaksi SUKSES');
        $this->get($base.'/'.$order->id, $headers)->assertOk()->assertJsonPath('props.attempts.0.supplier_response.message', 'Transaksi SUKSES');
        $order->update(['status' => 'success', 'payment_status' => 'paid', 'result' => 'PRIVATE-RESULT']);
        $headers['X-Inertia-Partial-Data'] = 'store,order,attempts,canResolve,canResend,canReveal,canReview,canCancel,canManual,canRetrySupplier,decisions';
        $this->get($base.'/'.$order->id, $headers)->assertOk()->assertJsonPath('props.order.status', 'success')
            ->assertJsonPath('props.order.payment_status', 'paid')->assertJsonPath('props.canManual', false)
            ->assertJsonPath('props.canReveal', false)->assertJsonMissingPath('props.order.result')
            ->assertJsonMissingPath('props.order.phone')->assertJsonMissingPath('props.attempts.0.request_snapshot');
        $other = $this->store();
        $this->get('/stores/'.$other->id.'/orders/'.$order->id, $headers)->assertForbidden();
        Http::assertNothingSent();
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

            return Http::response(['data' => ['ref_id' => $r['ref_id'], 'buyer_sku_code' => strtolower($r['buyer_sku_code']), 'customer_no' => $r['customer_no'], 'status' => $status, 'rc' => $status === 'Pending' ? '03' : '00', 'price' => 52000, 'selling_price' => 52000, 'admin' => 2500, 'customer_name' => 'Nama Dummy', 'periode' => '202610', 'sn' => 'BILL-SN']]);
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
        app(CustomerFulfillment::class)->retrySupplier($o);
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
        Cache::put('bot:'.$s->id.':state', ['status' => 'CONNECTED'], 90);
        $this->mock(BotManager::class, function ($mock) use (&$otp) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('command')->once()->withArgs(function ($store, $action, $data) use (&$otp) {
                preg_match('/\b([0-9]{6})\b/', $data['message'], $match);
                $otp = $match[1];

                return $action === 'message';
            });
        });
        $base = 'http://'.$s->slug.'.localhost';
        $this->post($base.'/account/otp', ['phone' => '081234567890', 'name' => 'Customer Dummy', 'email' => 'buyer@example.test', 'intent' => 'register', 'password' => 'registration-password-123', 'password_confirmation' => 'registration-password-123'])->assertSessionHasNoErrors();
        $this->post('http://'.$other->slug.'.localhost/account/verify', ['otp' => $otp])->assertSessionHasErrors('otp');
        $this->post($base.'/account/verify', ['otp' => $otp])->assertRedirect('/account');
        $customer = Customer::where('store_id', $s->id)->firstOrFail();
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertSame(0, $customer->balance);
        $this->post($base.'/account/verify', ['otp' => $otp])->assertSessionHasErrors('otp');
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $this->post($base.'/account/email/otp', ['email' => 'buyer@example.test'])->assertRedirect()->assertSessionHasNoErrors();
        $mail = Mail::sent(CustomerOtpMail::class)->first();
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
        $data = ['action' => 'buy', 'phone' => '6281234567890', 'product_id' => $p->id, 'destination' => '6289999999999', 'payment' => 'wallet', 'idempotency_key' => 'bot-test-123'];
        $this->postJson('/api/internal/bot/commerce', $data)->assertUnauthorized();
        $c = $this->customer($s);
        $response = $this->withToken($token)->postJson('/api/internal/bot/commerce', $data)->assertOk()->assertJsonPath('data.order.amount', 15000);
        $this->postJson('/api/internal/bot/commerce', $data)->assertJsonPath('data.order.id', $response->json('data.order.id'));
        $this->assertSame(85000, $c->fresh()->balance);
        $this->assertDatabaseCount('customer_orders', 1);
        $this->assertDatabaseHas('customer_orders', ['id' => $response->json('data.order.id'), 'destination' => $c->phone]);
    }

    public function test_gateway_creation_is_idempotent_and_qris_identity_does_not_change_with_amount(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aU1EAAAAASUVORK5CYII=');
        Http::fake([
            'api.sandbox.midtrans.com/v2/charge' => Http::response(['status_code' => '201', 'order_id' => $o->number, 'gross_amount' => $o->total.'.00', 'transaction_id' => 'test-qris-id', 'actions' => [['name' => 'generate-qr-code', 'method' => 'GET', 'url' => 'https://api.sandbox.midtrans.com/v2/qris/action-id/qr-code']]]),
            'api.sandbox.midtrans.com/v2/qris/action-id/qr-code' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        app(CustomerPayments::class)->start($o);
        Http::assertSentCount(2);
        app(CustomerPayments::class)->start($o->fresh());
        Http::assertSentCount(2);
        $this->assertSame('data:image/png;base64,'.base64_encode($png), $o->fresh()->qr_image);
        $qris = app(CustomQris::class);
        $dynamic = $qris->amount($this->qris(), 50001);
        $this->assertSame($qris->identity($this->qris()), $qris->identity($dynamic));
        try {
            $qris->identity(substr($this->qris(), 0, -4).'0000');
            $this->fail('CRC expected');
        } catch (ValidationException) {
        }
    }

    public function test_existing_midtrans_qr_is_repaired_without_another_charge_and_failed_images_can_retry(): void
    {
        $s = $this->store();
        $p = $this->product($s);
        $this->gateway($s);
        $o = app(CustomerCommerce::class)->begin($s, $this->input($p, ['payment' => 'gateway']), null);
        $o->update(['provider_id' => 'existing-id', 'qr_image' => 'https://outside.invalid/qr-code']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aU1EAAAAASUVORK5CYII=');
        Http::fake(['api.sandbox.midtrans.com/v2/qris/existing-id/qr-code' => Http::sequence()->push(['message' => 'Not ready'], 503)->push($png, 200, ['Content-Type' => 'image/png'])]);
        $o->refresh();
        $this->assertSame('midtrans', $o->gateway);
        $this->assertSame('awaiting_payment', $o->status);
        $this->assertFalse($o->expires_at->isPast());
        try {
            app(CustomerPayments::class)->repairQr($o);
            $this->fail('Invalid image must be rejected');
        } catch (ValidationException) {
        }
        $this->assertSame('existing-id', $o->fresh()->provider_id);
        app(CustomerPayments::class)->start($o->fresh());
        $this->assertSame('data:image/png;base64,'.base64_encode($png), $o->fresh()->qr_image);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' || str_contains($request->url(), 'outside.invalid'));
        $this->assertDatabaseCount('customer_orders', 1);
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

    public function test_wallet_purchase_queues_fulfillment_after_commit_once_and_not_on_rollback(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $product = $this->product($store);
        $engine = app(CustomerCommerce::class);
        $input = $this->input($product);
        DB::beginTransaction();
        $order = $engine->begin($store, $input, $customer);
        Queue::assertNotPushed(ProcessCustomerOrder::class);
        DB::commit();
        Queue::assertPushed(ProcessCustomerOrder::class, fn ($job) => $job->orderId === $order->id);
        $engine->begin($store, $input, $customer);
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
        DB::beginTransaction();
        $rolledBack = $engine->begin($store, $this->input($product), $customer);
        DB::rollBack();
        Queue::assertNotPushed(ProcessCustomerOrder::class, fn ($job) => $job->orderId === $rolledBack->id);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame('ready', $order->attempts()->firstOrFail()->status);
    }

    public function test_gateway_product_payment_queues_once_while_topup_and_late_payment_do_not(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $product = $this->product($store);
        $this->gateway($store);
        $engine = app(CustomerCommerce::class);
        $order = $engine->begin($store, $this->input($product, ['payment' => 'gateway']), $customer);
        Queue::assertNotPushed(ProcessCustomerOrder::class);
        $engine->paid($order, 'test:product-payment', $order->total, now());
        $engine->paid($order, 'test:product-payment', $order->total, now());
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
        Queue::assertPushed(ProcessCustomerOrder::class, fn ($job) => $job->orderId === $order->id);
        $topup = $engine->begin($store, ['purpose' => 'topup', 'amount' => 50000, 'phone' => $customer->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $customer);
        $engine->paid($topup, 'test:topup-payment', $topup->total, now());
        $late = $engine->begin($store, $this->input($product, ['payment' => 'gateway']), $customer);
        $late->update(['expires_at' => now()->subMinute()]);
        $engine->paid($late, 'test:late-payment', $late->total, now());
        Queue::assertPushed(ProcessCustomerOrder::class, 1);
        $this->assertSame('success', $topup->fresh()->status);
        $this->assertSame('review', $late->fresh()->status);
    }

    public function test_queued_wallet_job_processes_stock_without_waiting_for_scheduler(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $order = app(CustomerCommerce::class)->begin($store, $this->input($this->product($store)), $customer);
        $job = Queue::pushed(ProcessCustomerOrder::class)->first();
        $job->handle(app(CustomerFulfillment::class));
        $job->handle(app(CustomerFulfillment::class));
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(85000, $customer->fresh()->balance);
        $this->assertSame(1, PremiumStock::where('status', 'sent')->count());
    }

    public static function automaticPricingKinds(): array
    {
        return [['ppob'], ['premium']];
    }

    #[DataProvider('automaticPricingKinds')]
    public function test_highest_source_sets_price_but_prepaid_and_premium_failover_try_cheapest_first(string $kind): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $product = $this->product($store, 0);
        $product->update(['kind' => $kind, 'markup' => '1000', 'auto_update' => true]);
        $cheap = $this->remote($store, $product, 8000);
        $cheap->update(['kind' => $kind, 'sku' => 'CHEAP']);
        $expensive = $cheap->replicate();
        $expensive->fill(['cost' => 12000, 'sku' => 'EXPENSIVE'])->save();
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'digiflazz', 'supplier_product_id' => $expensive->id, 'source_key' => 'item:'.$expensive->id, 'active' => true]);
        $skus = [];
        Http::fake(function ($request) use (&$skus) {
            $skus[] = $request['buyer_sku_code'];
            $failed = $request['buyer_sku_code'] === 'CHEAP';

            return Http::response(['data' => ['ref_id' => $request['ref_id'], 'buyer_sku_code' => $request['buyer_sku_code'], 'customer_no' => $request['customer_no'], 'status' => $failed ? 'Gagal' : 'Sukses', 'rc' => $failed ? '44' : '00', 'price' => $failed ? 8000 : 12000, 'sn' => 'TEST-SUCCESS']]);
        });
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product), $customer);
        $this->assertSame(13000, $order->amount);
        $this->assertSame([8000, 12000], $order->attempts()->orderBy('position')->pluck('cost')->all());
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(['CHEAP', 'EXPENSIVE'], $skus);
        $this->assertSame(87000, $customer->fresh()->balance);
        $this->assertNull($order->fresh()->refunded_at);
    }

    public function test_premium_price_covers_highest_ready_stock_while_delivery_uses_cheapest_stock_first(): void
    {
        $store = $this->store();
        $customer = $this->customer($store);
        $product = $this->product($store);
        $product->update(['markup' => '10%', 'auto_update' => true]);
        $expensive = PremiumStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'payload' => 'EXPENSIVE-TEST', 'cost' => 18000]);
        $this->remote($store, $product, 12000);
        Http::fake(fn ($request) => Http::response(['data' => ['ref_id' => $request['ref_id'], 'buyer_sku_code' => $request['buyer_sku_code'], 'customer_no' => $request['customer_no'], 'status' => 'Gagal', 'rc' => '44', 'price' => 12000]]));
        $first = app(CustomerCommerce::class)->begin($store, $this->input($product), $customer);
        $this->assertSame(19800, $first->amount);
        app(CustomerFulfillment::class)->run($first);
        $this->assertSame('success', $first->fresh()->status);
        $this->assertSame(10000, $first->attempts()->where('status', 'success')->sole()->cost);
        $this->assertSame('ready', $expensive->fresh()->status);
        Http::assertNothingSent();
        $second = app(CustomerCommerce::class)->begin($store, $this->input($product), $customer);
        $this->assertSame(19800, $second->amount);
        $this->assertSame([12000, 18000], $second->attempts()->orderBy('position')->pluck('cost')->all());
        app(CustomerFulfillment::class)->run($second);
        $this->assertSame('success', $second->fresh()->status);
        $this->assertSame('sent', $expensive->fresh()->status);
        $this->assertSame(60400, $customer->fresh()->balance);
        Http::assertSentCount(1);
    }

    public function test_premium_stock_basis_can_switch_between_cheapest_and_highest_without_changing_dispatch_order(): void
    {
        $store = $this->store();
        $product = $this->product($store);
        $product->update(['markup' => '10%', 'auto_update' => true]);
        $basisRule = PricingRule::create(['store_id' => $store->id, 'scope' => 'category', 'key' => mb_strtolower($product->category), 'pricing_basis' => 'cheapest']);
        PremiumStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'payload' => 'TEST-ONLY', 'cost' => 18000]);
        $catalog = app(StoreCatalog::class);
        $low = $catalog->present($catalog->query($store, false)->findOrFail($product->id), $store, true);
        $this->assertSame(11000, $low['price']);
        $basisRule->update(['pricing_basis' => 'highest']);
        $catalog->reprice($store);
        $high = $catalog->present($catalog->query($store, false)->findOrFail($product->id), $store, true);
        $this->assertSame(19800, $high['price']);
        $this->assertSame(10000, $low['candidates'][0]['cost']);
        $this->assertSame(10000, $high['candidates'][0]['cost']);
        $order = app(CustomerCommerce::class)->begin($store, $this->input($product), $this->customer($store));
        app(CustomerFulfillment::class)->run($order);
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(10000, $order->attempts()->where('status', 'success')->sole()->cost);
        Http::assertNothingSent();
    }
}
