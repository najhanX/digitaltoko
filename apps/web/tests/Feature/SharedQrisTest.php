<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerPayments;
use App\Services\CustomQris;
use App\Services\SharedQris;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SharedQrisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.forwarder.shared_broker' => false, 'platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false]);
    }

    private function store(?User $owner = null): Store
    {
        return Store::create(['owner_id' => ($owner ?? User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.random_int(10000, 99999)]))->id, 'name' => 'Toko '.Str::random(5), 'slug' => Str::lower(Str::random(12)), 'status' => 'active', 'expires_at' => now()->addMonth(), 'template' => 'aurora']);
    }

    private function payload(): string
    {
        $payload = '00020101021126080004TEST5204000053033605802ID5904TEST6007JAKARTA6304';
        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($payload); $i++) {
            $crc ^= ord($payload[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = (($crc & 0x8000) ? ($crc << 1) ^ 0x1021 : $crc << 1) & 0xFFFF;
            }
        }

        return $payload.strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }

    private function connect(Store $store, ?string $profile = null): array
    {
        $credentials = app(SharedQris::class)->connect($store, ['qris_payload' => $this->payload(), 'application_package' => 'id.dana'], $profile);
        app(CustomerCommerce::class)->settings($store)->update(['gateway' => 'custom', 'custom_licensed_at' => now(), 'credentials' => $credentials]);

        return $credentials;
    }

    private function topup(Store $store, array $extra = []): CustomerOrder
    {
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Pelanggan', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'balance' => 0]);

        return app(CustomerCommerce::class)->begin($store, [...['purpose' => 'topup', 'amount' => 50000, 'destination' => $customer->phone, 'phone' => $customer->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], ...$extra], $customer);
    }

    public function test_one_profile_endpoint_routes_to_the_right_store_and_replays_without_double_credit(): void
    {
        $first = $this->store();
        $second = $this->store($first->owner);
        $profile = $this->connect($first);
        $this->connect($second, $profile['qris_profile_id']);
        $a = $this->topup($first);
        $b = $this->topup($second);
        $this->assertNotSame($a->total, $b->total);
        $url = '/api/commerce/qris-profiles/'.$profile['qris_profile_id'].'/forward';
        $data = ['event_id' => 'original-event-1', 'application_package' => 'id.dana', 'title' => 'Pembayaran masuk', 'text' => 'Rp'.number_format($b->total, 0, ',', '.').' pembayaran diterima', 'occurred_at' => now()->addSecond()->toIso8601String()];
        $this->withToken(str_repeat('x', 40))->postJson($url, $data)->assertUnauthorized();
        $this->withToken($profile['forward_token'])->postJson($url, $data)->assertOk();
        $this->postJson($url, $data)->assertOk();
        $this->assertSame('paid', $b->fresh()->payment_status);
        $this->assertSame('unpaid', $a->fresh()->payment_status);
        $this->assertSame(50000, $b->customer->fresh()->balance);
        $this->assertDatabaseCount('qris_events', 1);
        $this->assertDatabaseCount('commerce_receipts', 1);
    }

    public function test_unknown_money_is_recorded_once_and_cannot_be_reassigned_to_a_later_invoice(): void
    {
        $store = $this->store();
        $profile = $this->connect($store);
        $merchant = hash('sha256', app(CustomQris::class)->identity($profile['qris_payload']));
        $shared = app(SharedQris::class);
        $at = now();
        $shared->ingest($profile, 'bank-unknown', 10000, $at, 'id.dana');
        $shared->ingest($profile, 'bank-unknown', 10000, $at, 'id.dana');
        $this->assertDatabaseHas('qris_events', ['amount' => 10000, 'status' => 'unmatched']);
        $this->assertDatabaseCount('qris_events', 1);
        $this->assertDatabaseCount('commerce_receipts', 0);
    }

    public function test_profile_cannot_be_claimed_or_selected_by_another_owner(): void
    {
        $a = $this->store();
        $profile = $this->connect($a);
        $b = $this->store();
        $this->actingAs($b->owner)->get('/stores/'.$b->id.'/payments')->assertOk()->assertSee('qrisProfiles');
        app(CustomerCommerce::class)->settings($b)->update(['custom_licensed_at' => now()]);
        $this->post('/stores/'.$b->id.'/payments', ['action' => 'connection', 'gateway' => 'custom', 'sandbox' => false, 'qris_profile_id' => $profile['qris_profile_id']])->assertNotFound();
        $this->expectException(ValidationException::class);
        $this->connect($b);
    }

    public function test_broker_reservation_and_recovery_keep_the_same_order_and_receipt(): void
    {
        config(['services.forwarder.shared_broker' => true, 'services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('t', 40)]);
        $store = $this->store();
        $profile = $this->connect($store);
        $reference = null;
        Http::fake(function ($request) use (&$reference, $store) {
            if (str_ends_with($request->url(), '/qris/reserve')) {
                $reference = $request['reference'];

                return Http::response(['status' => 'SUCCESS', 'data' => ['total' => 50777, 'expires_at' => now()->addMinutes(15)->toIso8601String()]]);
            }

            return Http::response(['status' => 'SUCCESS', 'data' => ['receipt' => ['id' => 'broker-receipt', 'status' => 'store', 'reference' => $reference, 'store_id' => $store->id, 'amount' => 50777, 'occurred_at' => now()->addSecond()->toIso8601String()]]]);
        });
        $order = $this->topup($store);
        $this->assertSame($reference, $order->id);
        $this->assertSame(777, $order->unique_code);
        app(CustomerPayments::class)->refresh($order);
        app(CustomerPayments::class)->refresh($order->fresh());
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(50000, $order->customer->fresh()->balance);
        $this->assertDatabaseCount('commerce_receipts', 1);
    }

    public function test_broker_outage_returns_retryable_http_status_instead_of_losing_the_receipt(): void
    {
        config(['services.forwarder.shared_broker' => true, 'services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('t', 40)]);
        Http::fake(['*' => Http::response(['status' => 'ERROR'], 503)]);
        $store = $this->store();
        $profile = $this->connect($store);
        $this->withToken($profile['forward_token'])->postJson('/api/commerce/qris-profiles/'.$profile['qris_profile_id'].'/forward', ['event_id' => 'retry-event', 'application_package' => 'id.dana', 'title' => 'Pembayaran masuk', 'text' => 'Rp10.000 pembayaran diterima', 'occurred_at' => now()->toIso8601String()])->assertStatus(503);
        $this->assertDatabaseCount('qris_events', 0);
    }

    public function test_apk_connection_probe_does_not_create_a_payment_or_mutation(): void
    {
        $store = $this->store();
        $profile = $this->connect($store);
        $this->withToken($profile['forward_token'])->postJson('/api/commerce/qris-profiles/'.$profile['qris_profile_id'].'/forward', ['event_id' => 'test-'.Str::uuid(), 'application_package' => 'id.dana', 'title' => 'Uji koneksi', 'text' => 'Uji koneksi HansDev Forwarder — bukan pembayaran', 'occurred_at' => now()->toIso8601String()])->assertOk()->assertJsonPath('status', 'SUCCESS');
        $this->assertDatabaseCount('qris_events', 0);
        $this->assertDatabaseCount('commerce_receipts', 0);
    }

    public function test_preparation_adopts_existing_invoice_without_changing_the_price_or_crediting_balance(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('t', 40)]);
        $store = $this->store();
        $this->connect($store);
        $order = $this->topup($store);
        Http::fake(function ($request) use ($order) {
            return Http::response(['status' => 'SUCCESS', 'data' => str_ends_with($request->url(), '/config') ? ['enabled' => true, 'merchant' => str_repeat('a', 64), 'owner' => 'HD001'] : ['total' => (int) $order->total, 'expires_at' => $order->expires_at->toIso8601String()]]);
        });
        $this->artisan('digitaltoko:qris-shared-prepare', ['--apply' => true])->assertSuccessful();
        $this->assertSame($order->id, $order->fresh()->payment_credentials['shared_reservation']);
        $this->assertSame('unpaid', $order->fresh()->payment_status);
        $this->assertSame(0, $order->customer->fresh()->balance);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/reserve') && $request['existing_total'] === $order->total && $request['reference'] === $order->id);
    }
}
