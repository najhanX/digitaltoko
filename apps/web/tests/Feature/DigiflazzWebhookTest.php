<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerOrder;
use App\Jobs\ProcessDigiflazzCallback;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerNotice;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class DigiflazzWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'cache.default' => 'array', 'suppliers.cache_store' => 'array', 'inertia.ssr.enabled' => false]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function pending(bool $sandbox = true): array
    {
        $store = Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD-'.Str::random(12)])->id, 'name' => 'Webhook test', 'slug' => Str::lower(Str::random(10)), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addMonth(), 'sandbox' => $sandbox]);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Test', 'phone_verified_at' => now(), 'phone' => '6281234567890', 'balance' => 100000, 'sandbox_balance' => 100000]);
        $connection = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'digiflazz', 'enabled' => true, 'credentials' => ['username' => 'buyer', 'api_key' => 'prod-secret', 'development_key' => 'dev-secret', 'webhook_secret' => 'test-webhook-secret-123456789']]);
        $product = CatalogProduct::create(['store_id' => $store->id, 'code' => 'D-XLD10', 'name' => 'XL test', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'XL', 'sale_price' => 15000, 'active' => true, 'auto_update' => false]);
        $item = SupplierProduct::create(['store_id' => $store->id, 'connection_id' => $connection->id, 'sku' => 'xld10', 'mode' => 'prepaid', 'name' => 'XL test', 'kind' => 'ppob', 'service' => 'Pulsa', 'category' => 'XL', 'cost' => 10000, 'available' => true, 'observed_at' => now()]);
        ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'source' => 'digiflazz', 'source_key' => 'item:'.$item->id, 'supplier_product_id' => $item->id, 'active' => true]);
        $order = app(CustomerCommerce::class)->begin($store, ['product_id' => $product->id, 'phone' => $customer->phone, 'destination' => '087800001234', 'payment' => 'wallet', 'idempotency_key' => (string) Str::uuid()], $customer, 'bot');
        $attempt = $order->attempts()->firstOrFail();
        $attempt->update(['status' => 'pending', 'last_checked_at' => now()]);
        $order->update(['status' => 'pending']);
        $body = ['ref_id' => $attempt->reference, 'buyer_sku_code' => 'xld10', 'customer_no' => $order->destination, 'status' => 'Gagal', 'rc' => '02', 'price' => 10000];

        return [$store, $customer, $connection, $order, $attempt, $body];
    }

    private function sendCallback(array $body, string $secret = 'test-webhook-secret-123456789')
    {
        $raw = json_encode(['data' => $body]);

        return $this->call('POST', '/api/commerce/digiflazz', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => 'sha1='.hash_hmac('sha1', $raw, $secret), 'HTTP_X_DIGIFLAZZ_EVENT' => 'update'], $raw);
    }

    public function test_failed_callback_finishes_and_refunds_once_without_polling_supplier(): void
    {
        foreach ([true, false] as $sandbox) {
            [$store, $customer, $connection, $order, $attempt, $body] = $this->pending($sandbox);
            $this->sendCallback($body)->assertOk();
            $this->sendCallback($body)->assertOk();
            $this->assertSame('failed', $order->fresh()->status);
            $this->assertSame('failed', $attempt->fresh()->status);
            $this->assertSame(100000, $customer->fresh()->balance);
            $this->assertSame(100000, $customer->fresh()->sandbox_balance);
            $this->assertSame(1, CustomerNotice::where('order_id', $order->id)->where('event', 'order:'.$order->id.':failed')->count());
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('customer_ledgers', 4);
    }

    public function test_success_is_idempotent_and_late_failure_cannot_reverse_it(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $success = [...$body, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'SERIAL-TEST'];
        $this->sendCallback($success)->assertOk();
        $this->sendCallback($success)->assertOk();
        $this->sendCallback($body)->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('SERIAL-TEST', $order->fresh()->result);
        $this->assertSame(85000, $customer->fresh()->sandbox_balance);
        $this->assertSame(1, CustomerNotice::where('event', 'order:'.$order->id.':success')->count());
        Http::assertNothingSent();
    }

    public function test_wrong_signature_tampering_and_other_store_secret_are_rejected(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $this->sendCallback($body, 'another-store-secret-123456789')->assertUnauthorized();
        $this->sendCallback([...$body, 'buyer_sku_code' => 'OTHER'])->assertUnprocessable();
        $this->sendCallback([...$body, 'customer_no' => 'OTHER'])->assertUnprocessable();
        $this->call('POST', '/api/commerce/digiflazz', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => 'sha1='.hash_hmac('sha1', json_encode(['data' => $body]), 'test-webhook-secret-123456789')], json_encode(['data' => [...$body, 'status' => 'Sukses']]))->assertUnauthorized();
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertNull($order->fresh()->refunded_at);
        Http::assertNothingSent();
    }

    public function test_unknown_result_or_success_without_valid_price_does_not_refund_or_deliver(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $this->sendCallback([...$body, 'rc' => '999'])->assertOk();
        $this->assertSame('pending', $order->fresh()->status);
        $this->sendCallback([...$body, 'status' => 'Sukses', 'rc' => '00', 'price' => 999999])->assertOk();
        $this->assertSame('review', $order->fresh()->status);
        $this->assertNull($order->fresh()->refunded_at);
    }

    public function test_concurrent_poll_queues_verified_callback_and_disconnected_source_still_accepts_final_callback(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $lock = Cache::lock('customer-fulfill:'.$order->id, 120);
        $lock->get();
        $this->sendCallback($body)->assertOk();
        Queue::assertPushed(ProcessDigiflazzCallback::class, fn ($job) => $job->attemptId === $attempt->id);
        $this->assertSame('pending', $order->fresh()->status);
        $lock->release();
        (new ProcessDigiflazzCallback($attempt->id, $body))->handle(app(CustomerFulfillment::class));
        $this->assertSame('failed', $order->fresh()->status);
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $this->actingAs($store->owner)->delete('/stores/'.$store->id.'/sources/'.$connection->id)->assertRedirect();
        $this->assertFalse($connection->fresh()->enabled);
        $this->sendCallback($body)->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_ready_unpaid_or_postpaid_inquiry_cannot_be_fulfilled_by_callback(): void
    {
        foreach (['ready', 'unpaid', 'inquiry'] as $scenario) {
            [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
            if ($scenario === 'ready') {
                $attempt->update(['status' => 'ready']);
            }
            if ($scenario === 'unpaid') {
                $order->update(['payment_status' => 'unpaid']);
            }
            if ($scenario === 'inquiry') {
                $attempt->update(['request_snapshot' => [...$attempt->request_snapshot, 'kind' => 'postpaid', 'pay_started' => false]]);
            }
            $this->sendCallback([...$body, 'status' => 'Sukses', 'rc' => '00'])->assertOk();
            $this->assertSame('pending', $order->fresh()->status);
        }
    }

    public function test_failed_source_queues_next_mapping_without_calling_it_during_webhook(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $next = $attempt->replicate();
        $next->reference = 'DT'.Str::ulid();
        $next->position = 2;
        $next->status = 'ready';
        $next->save();
        $this->sendCallback($body)->assertOk();
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame('ready', $next->fresh()->status);
        $this->assertNull($order->fresh()->refunded_at);
        Queue::assertPushed(ProcessCustomerOrder::class, fn ($job) => $job->orderId === $order->id);
        Http::assertNothingSent();
    }

    public function test_tunnel_callback_url_does_not_change_platform_url_and_defaults_to_app_url(): void
    {
        [$store] = $this->pending();
        config(['suppliers.digiflazz_webhook_url' => 'https://callback.example.test/api/commerce/digiflazz']);
        $this->actingAs($store->owner)->get('/stores/'.$store->id.'/sources')->assertInertia(fn ($page) => $page->where('digiflazzWebhookUrl', 'https://callback.example.test/api/commerce/digiflazz'));
        $this->assertSame('http://localhost', config('app.url'));
        config(['suppliers.digiflazz_webhook_url' => null]);
        $this->get('/stores/'.$store->id.'/sources')->assertInertia(fn ($page) => $page->where('digiflazzWebhookUrl', 'http://localhost/api/commerce/digiflazz'));
    }

    public function test_ping_checks_endpoint_reachability_without_changing_any_order(): void
    {
        [$store, $customer, $connection, $order] = $this->pending();
        $this->postJson('/api/commerce/digiflazz', ['sed' => 'test', 'hook_id' => 'hook-test'], ['X-Digiflazz-Event' => 'ping'])->assertOk()->assertJsonPath('event', 'ping');
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_only_owner_can_set_secret_and_rotation_waits_for_pending_orders(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $url = '/stores/'.$store->id.'/sources/'.$connection->id.'/webhook';
        $this->actingAs(User::factory()->create(['role' => 'owner']))->post($url, ['webhook_secret' => 'new-secret-123456789'])->assertForbidden();
        $this->actingAs($store->owner)->post($url, ['webhook_secret' => 'new-secret-123456789'])->assertSessionHasErrors('webhook_secret');
        $connection->update(['credentials' => ['username' => 'buyer', 'development_key' => 'dev-secret']]);
        $this->actingAs($store->owner)->post($url, ['webhook_secret' => 'test-webhook-secret-123456789'])->assertSessionHasNoErrors();
        $this->assertSame('dev-secret', $connection->fresh()->credentials['development_key']);
        $this->sendCallback($body)->assertOk();
        $this->actingAs($store->owner)->post($url, ['webhook_secret' => 'new-secret-123456789'])->assertSessionHasNoErrors();
    }
}
