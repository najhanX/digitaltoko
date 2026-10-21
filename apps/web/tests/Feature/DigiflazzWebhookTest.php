<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerOrder;
use App\Jobs\ProcessDigiflazzCallback;
use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerNotice;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Services\CustomerFulfillment;
use App\Services\CustomerWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function test_automatic_success_is_idempotent_and_late_failure_refunds_it(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $success = [...$body, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'SERIAL-TEST'];
        $this->sendCallback($success)->assertOk();
        $this->sendCallback($success)->assertOk();
        $this->sendCallback($body)->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame('', $order->fresh()->result);
        $this->assertSame(100000, $customer->fresh()->sandbox_balance);
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

    private function manualUrl($store, $order): string
    {
        return '/stores/'.$store->id.'/orders/'.$order->id;
    }

    public function test_manual_failure_refund_is_reconciled_by_callback_and_repeated_callbacks_are_idempotent(): void
    {
        foreach ([true, false] as $sandbox) {
            [$store, $customer, $connection, $order, $attempt, $body] = $this->pending($sandbox);
            $url = $this->manualUrl($store, $order);
            $this->actingAs($store->owner)->post($url, ['action' => 'manual', 'status' => 'failed', 'note' => 'Diperiksa langsung oleh toko'])->assertSessionHasNoErrors();
            $this->assertSame('failed', $order->fresh()->status);
            $field = $sandbox ? 'sandbox_balance' : 'balance';
            $this->assertSame(100000, $customer->fresh()->$field);
            $this->post($url, ['action' => 'manual', 'status' => 'failed', 'note' => 'Ulang keputusan yang sama'])->assertSessionHasErrors();
            $success = [...$body, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'ACTUAL-SN'];
            $this->sendCallback($success)->assertOk();
            $this->sendCallback($success)->assertOk();
            $this->assertSame('success', $order->fresh()->status);
            $this->assertSame('ACTUAL-SN', $order->fresh()->result);
            $this->assertNull($order->fresh()->refunded_at);
            $this->assertSame(85000, $customer->fresh()->$field);
            $this->sendCallback([...$success, 'sn' => 'CORRECTED-SN'])->assertOk();
            $this->assertSame('CORRECTED-SN', $order->fresh()->result);
            $this->assertSame(85000, $customer->fresh()->$field);
            $this->sendCallback($body)->assertOk();
            $this->sendCallback($body)->assertOk();
            $this->assertSame('failed', $order->fresh()->status);
            $this->assertSame(100000, $customer->fresh()->$field);
            $this->assertCount(4, $order->fresh()->snapshot['decision_history']);
        }
        Http::assertNothingSent();
    }

    public function test_manual_success_can_be_replaced_by_failed_callback_and_stops_other_parsing(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $queuedBefore = Queue::pushed(ProcessCustomerOrder::class)->count();
        $next = $attempt->replicate();
        $next->reference = 'DT'.Str::ulid();
        $next->position = 2;
        $next->status = 'ready';
        $next->last_checked_at = null;
        $next->save();
        $this->actingAs($store->owner)->post($this->manualUrl($store, $order), ['action' => 'manual', 'status' => 'success', 'result' => 'MANUAL-SN', 'note' => 'Sudah diperiksa oleh toko'])->assertSessionHasNoErrors();
        $this->assertSame('MANUAL-SN', $order->fresh()->result);
        $this->sendCallback([...$body, 'status' => 'Pending', 'rc' => '03'])->assertOk();
        $this->assertSame('pending', $order->fresh()->status);
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->sendCallback($body)->assertOk();
        app(CustomerFulfillment::class)->run($order->fresh());
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame('ready', $next->fresh()->status);
        $this->assertSame($queuedBefore, Queue::pushed(ProcessCustomerOrder::class)->count());
        $this->assertSame(100000, $customer->fresh()->sandbox_balance);
        Http::assertNothingSent();
        $this->actingAs($store->owner)->post('/stores/'.$store->id.'/sources/'.$connection->id.'/webhook', ['webhook_secret' => 'new-secret-123456789'])->assertSessionHasNoErrors();
        $this->sendCallback([...$body, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'NEW-KEY-SN'], 'new-secret-123456789')->assertOk();
        $this->assertSame('success', $order->fresh()->status);
    }

    public function test_manual_callback_can_reverse_refund_into_negative_balance_once(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $this->actingAs($store->owner)->post($this->manualUrl($store, $order), ['action' => 'manual', 'status' => 'failed', 'note' => 'Diperiksa langsung oleh toko'])->assertSessionHasNoErrors();
        $customer->update(['sandbox_balance' => 0]);
        $success = [...$body, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'DELIVERED'];
        $this->sendCallback($success)->assertOk();
        $this->sendCallback($success)->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('success', $attempt->fresh()->status);
        $this->assertSame('DELIVERED', $order->fresh()->result);
        $this->assertSame(-15000, $customer->fresh()->sandbox_balance);
        $this->assertNull($order->fresh()->refunded_at);
    }

    public function test_manual_action_is_scoped_and_requires_explicit_staff_permission(): void
    {
        [$store, $customer, $connection, $order] = $this->pending();
        $url = $this->manualUrl($store, $order);
        $payload = ['action' => 'manual', 'status' => 'failed', 'note' => 'Diperiksa langsung oleh toko'];
        $this->actingAs(User::factory()->create(['role' => 'owner']))->post($url, $payload)->assertForbidden();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Operator', 'username' => 'operator', 'email' => 'operator@example.test', 'password' => 'password', 'permissions' => ['orders.view']]);
        $this->actingAs($staff, 'staff')->post($url, $payload)->assertForbidden();
        $staff->update(['permissions' => ['orders.manage']]);
        $this->actingAs($staff, 'staff')->post($url, [...$payload, 'note' => ''])->assertSessionHasErrors('note');
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame('staff:'.$staff->id, $order->fresh()->snapshot['manual_decision']['actor']);
    }

    public function test_manual_action_does_not_mark_unpaid_or_unsent_order_as_success(): void
    {
        foreach (['unpaid', 'ready'] as $scenario) {
            [$store, $customer, $connection, $order, $attempt] = $this->pending();
            if ($scenario === 'unpaid') {
                $order->update(['payment_status' => 'unpaid']);
            } else {
                $attempt->update(['status' => 'ready', 'last_checked_at' => null]);
            }
            $this->actingAs($store->owner)->post($this->manualUrl($store, $order), ['action' => 'manual', 'status' => 'success', 'note' => 'Coba keputusan yang tidak sah'])->assertSessionHasErrors();
            $this->assertSame('pending', $order->fresh()->status);
        }
    }

    public function test_manual_decision_waits_for_fulfillment_lock_and_rejects_other_attempt_callback(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $url = $this->manualUrl($store, $order);
        $payload = ['action' => 'manual', 'status' => 'failed', 'note' => 'Diperiksa langsung oleh toko'];
        $lock = Cache::lock('customer-fulfill:'.$order->id, 120);
        $lock->get();
        $this->actingAs($store->owner)->post($url, $payload)->assertSessionHasErrors();
        $this->assertSame('pending', $order->fresh()->status);
        $lock->release();
        $this->post($url, $payload)->assertSessionHasNoErrors();
        $other = $attempt->replicate();
        $other->reference = 'DT'.Str::ulid();
        $other->position = 2;
        $other->status = 'ready';
        $other->last_checked_at = null;
        $other->save();
        $this->sendCallback([...$body, 'ref_id' => $other->reference, 'status' => 'Sukses', 'rc' => '00'])->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
        $this->sendCallback([...$body, 'status' => 'Sukses', 'rc' => '00'], 'wrong-secret-123456789')->assertUnauthorized();
        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_manual_guest_failure_does_not_credit_wallet_and_new_callback_notifies_again(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $order->update(['customer_id' => null]);
        $this->actingAs($store->owner)->post($this->manualUrl($store, $order), ['action' => 'manual', 'status' => 'failed', 'note' => 'Diperiksa langsung oleh toko'])->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->refunded_at);
        $this->assertSame(85000, $customer->fresh()->sandbox_balance);
        $this->sendCallback([...$body, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'GUEST-SN'])->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame(2, CustomerNotice::where('order_id', $order->id)->where('event', 'like', '%:decision:%')->count());
        $this->post($this->manualUrl($store, $order), ['action' => 'resend'])->assertSessionHasNoErrors();
        $this->get($this->manualUrl($store, $order))->assertInertia(fn ($page) => $page->where('canManual', false)->has('decisions', 2));
    }

    public function test_automatic_failure_then_success_reclaims_refund_and_later_sn_changes_do_not_charge_again(): void
    {
        foreach ([true, false] as $sandbox) {
            [$store, $customer, $connection, $order, $attempt, $body] = $this->pending($sandbox);
            $this->sendCallback($body)->assertOk();
            $this->assertSame('failed', $order->fresh()->status);
            $field = $sandbox ? 'sandbox_balance' : 'balance';
            $customer->update([$field => 1000]);
            $success = [...$body, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'FIRST-SN'];
            $this->sendCallback($success)->assertOk();
            $this->sendCallback($success)->assertOk();
            $this->assertSame('success', $order->fresh()->status);
            $this->assertSame(-14000, $customer->fresh()->$field);
            $this->assertNull($order->fresh()->refunded_at);
            $this->sendCallback([...$success, 'sn' => 'CORRECTED-SN'])->assertOk();
            $this->assertSame('CORRECTED-SN', $order->fresh()->result);
            $this->assertSame(-14000, $customer->fresh()->$field);
            $this->sendCallback($body)->assertOk();
            $this->sendCallback($body)->assertOk();
            $this->assertSame(1000, $customer->fresh()->$field);
            $this->assertSame('failed', $order->fresh()->status);
            $this->assertSame(100000, $sandbox ? $customer->fresh()->balance : $customer->fresh()->sandbox_balance);
        }
        Http::assertNothingSent();
    }

    public function test_negative_customer_is_blocked_from_wallet_gateway_bot_and_guest_but_can_top_up_in_parts(): void
    {
        foreach ([true, false] as $sandbox) {
            [$store, $customer, $connection, $order] = $this->pending($sandbox);
            $field = $sandbox ? 'sandbox_balance' : 'balance';
            $customer->update([$field => -15000]);
            $engine = app(CustomerCommerce::class);
            foreach (['bot', 'web'] as $channel) {
                foreach (['wallet', 'gateway'] as $payment) {
                    try {
                        $engine->begin($store, ['product_id' => $order->product_id, 'phone' => $customer->phone, 'destination' => $order->destination, 'payment' => $payment, 'idempotency_key' => (string) Str::uuid()], $customer, $channel);
                        $this->fail('Negative customer purchase must be denied.');
                    } catch (ValidationException $error) {
                        $this->assertStringContainsString('minus', $error->getMessage());
                    }
                }
            }
            try {
                $engine->begin($store, ['product_id' => $order->product_id, 'phone' => $customer->phone, 'destination' => $order->destination, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], null, 'web');
                $this->fail('Guest cannot bypass a negative account with its registered phone.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString('minus', $error->getMessage());
            }
            $settings = $engine->settings($store);
            $settings->update(['gateway' => 'midtrans', 'credentials' => ['server_key' => 'fake-test-server-key']]);
            $topup = $engine->begin($store, ['purpose' => 'topup', 'amount' => 5000, 'phone' => $customer->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $customer);
            $engine->paid($topup, 'test-topup:'.$topup->id, $topup->total, now());
            $this->assertSame(-10000, $customer->fresh()->$field);
            $this->assertTrue($engine->purchaseBlocked($store, $customer, $customer->phone));
            $next = $engine->begin($store, ['purpose' => 'topup', 'amount' => 10000, 'phone' => $customer->phone, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $customer);
            $engine->paid($next, 'test-topup:'.$next->id, $next->total, now());
            $this->assertSame(0, $customer->fresh()->$field);
            $this->assertFalse($engine->purchaseBlocked($store, $customer, $customer->phone));
            $recovered = $engine->begin($store, ['product_id' => $order->product_id, 'phone' => $customer->phone, 'destination' => $order->destination, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $customer, 'bot');
            $this->assertSame('awaiting_payment', $recovered->fresh()->status);
        }
    }

    public function test_old_failed_parsing_callback_does_not_overwrite_successful_newer_parsing(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $next = $attempt->replicate();
        $next->reference = 'DT'.Str::ulid();
        $next->position = 2;
        $next->status = 'pending';
        $next->save();
        $this->sendCallback([...$body, 'ref_id' => $next->reference, 'status' => 'Sukses', 'rc' => '00', 'sn' => 'LATEST-SN'])->assertOk();
        $this->sendCallback($body)->assertOk();
        $this->assertSame('success', $order->fresh()->status);
        $this->assertSame('LATEST-SN', $order->fresh()->result);
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertSame(85000, $customer->fresh()->sandbox_balance);
    }

    public function test_negative_debt_is_isolated_per_store_and_environment_and_ordinary_debits_cannot_overdraw(): void
    {
        [$store, $customer] = $this->pending();
        $customer->update(['balance' => -15000, 'sandbox_balance' => 0]);
        $engine = app(CustomerCommerce::class);
        $this->assertFalse($engine->purchaseBlocked($store, $customer, $customer->phone));
        $store->update(['sandbox' => false]);
        $this->assertTrue($engine->purchaseBlocked($store, $customer, $customer->phone));
        [$another] = $this->pending(false);
        $this->assertFalse($engine->purchaseBlocked($another, null, $customer->phone));
        try {
            DB::transaction(fn () => app(CustomerWallet::class)->apply($customer, -1000, 'test:ordinary-debit', 'purchase'));
            $this->fail('Normal debit must not increase debt.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('tidak mencukupi', $error->getMessage());
        }
        $this->assertSame(-15000, $customer->fresh()->balance);
    }

    public function test_only_store_owner_can_load_webhook_secret_without_changing_pending_orders(): void
    {
        [$store, $customer, $connection, $order] = $this->pending();
        $url = '/stores/'.$store->id.'/sources/'.$connection->id.'/webhook-secret';
        $this->postJson($url)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'owner']))->postJson($url)->assertForbidden();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Operator', 'username' => 'secret-operator', 'email' => 'operator@example.test', 'password' => 'password', 'permissions' => ['products.manage']]);
        $this->actingAs($staff, 'staff')->postJson($url)->assertForbidden();
        $response = $this->actingAs($store->owner, 'web')->postJson($url)->assertOk()->assertJsonPath('secret', 'test-webhook-secret-123456789');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/stores/'.$store->id.'/sources')->assertDontSee('test-webhook-secret-123456789');
        [$otherStore, $otherCustomer, $otherConnection] = $this->pending();
        $this->postJson('/stores/'.$store->id.'/sources/'.$otherConnection->id.'/webhook-secret')->assertNotFound();
        $this->post('/stores/'.$store->id.'/sources/'.$connection->id.'/webhook', ['webhook_secret' => str_repeat('x', 256)])->assertSessionHasErrors('webhook_secret');
        $this->assertArrayNotHasKey('webhook_secret', session('_old_input', []));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame('test-webhook-secret-123456789', $connection->fresh()->credentials['webhook_secret']);
    }

    public function test_signature_rejection_explains_secret_mismatch_and_does_not_mutate_order(): void
    {
        [$store, $customer, $connection, $order, $attempt, $body] = $this->pending();
        $this->sendCallback($body, 'incorrect-secret-123456789')->assertUnauthorized()->assertJsonPath('message', 'Signature webhook Digiflazz tidak cocok. Samakan Secret ID Digiflazz dengan secret webhook tersimpan pada koneksi toko.');
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(85000, $customer->fresh()->sandbox_balance);
    }
}
