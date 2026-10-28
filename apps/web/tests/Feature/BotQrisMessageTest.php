<?php

namespace Tests\Feature;

use App\Models\BotQrisMessage;
use App\Models\CustomerNotice;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BotQrisMessageTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $token): Store
    {
        return Store::create(['owner_id' => User::factory()->create()->id, 'name' => 'QR test', 'slug' => Str::lower(Str::random(12)), 'category' => 'full', 'status' => 'active', 'expires_at' => now()->addMonth(), 'worker_token_hash' => hash('sha256', $token)]);
    }

    private function order(Store $store): CustomerOrder
    {
        return CustomerOrder::create(['store_id' => $store->id, 'number' => 'DT'.Str::ulid(), 'idempotency_key' => Str::uuid(), 'request_hash' => str_repeat('a', 64), 'channel' => 'bot', 'purpose' => 'product', 'status' => 'awaiting_payment', 'payment_status' => 'unpaid', 'gateway' => 'midtrans', 'phone' => '6281234567890', 'destination' => '08123456789', 'snapshot' => ['name' => 'QR dummy'], 'amount' => 10000, 'total' => 10000, 'expires_at' => now()->addHour()]);
    }

    private function payload(CustomerOrder $order, string $id = 'QRTEST1'): array
    {
        return ['order_id' => $order->id, 'phone' => $order->phone, 'key' => ['id' => $id, 'remoteJid' => $order->phone.'@s.whatsapp.net', 'fromMe' => true]];
    }

    public function test_keys_are_encrypted_and_all_qris_messages_become_revocable_only_after_verified_payment(): void
    {
        config(['platform.domain' => 'localhost']);
        $token = str_repeat('t', 40);
        $order = $this->order($this->shop($token));
        $url = '/api/internal/bot/qris-messages';
        $this->postJson($url, $this->payload($order))->assertUnauthorized();
        $first = $this->withToken($token)->postJson($url, $this->payload($order))->assertOk()->json('data.id');
        $this->postJson($url, $this->payload($order))->assertOk()->assertJsonPath('data.id', $first);
        $this->postJson($url, $this->payload($order, 'QRTEST2'))->assertOk();
        $this->assertDatabaseCount('bot_qris_messages', 2);
        $this->assertStringNotContainsString('QRTEST1', BotQrisMessage::findOrFail($first)->getRawOriginal('message_key'));
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(0, 'qris_revocations');
        $this->postJson($url.'/'.$first.'/revoked')->assertUnprocessable();
        $order->update(['payment_status' => 'paid', 'status' => 'pending']);
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(2, 'qris_revocations');
        $this->postJson($url.'/'.$first.'/revoked')->assertOk();
        $this->postJson($url.'/'.$first.'/revoked')->assertOk();
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(1, 'qris_revocations');
        $this->assertSame('pending', $order->fresh()->status);
        $order->delete();
        $this->assertDatabaseCount('bot_qris_messages', 0);
    }

    public function test_expired_and_overdue_unpaid_qris_are_revoked_without_changing_order_or_payment_state(): void
    {
        config(['platform.domain' => 'localhost']);
        $token = str_repeat('e', 40);
        $store = $this->shop($token);
        $this->withToken($token);
        foreach ([['awaiting_payment', false, false], ['awaiting_payment', true, true], ['expired', false, true], ['pending', true, false], ['review', true, false]] as $index => [$status, $overdue, $eligible]) {
            $order = $this->order($store);
            $order->update(['status' => $status, 'expires_at' => $overdue ? now()->subMinute() : now()->addHour()]);
            $id = $this->postJson('/api/internal/bot/qris-messages', $this->payload($order, 'EXPIRY-'.$index))->assertOk()->json('data.id');
            $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount($eligible ? 1 : 0, 'qris_revocations');
            $response = $this->postJson('/api/internal/bot/qris-messages/'.$id.'/revoked');
            if ($eligible) {
                $response->assertOk();
                $this->assertNotNull(BotQrisMessage::findOrFail($id)->revoked_at);
            } else {
                $response->assertUnprocessable();
            }
            $this->assertSame($status, $order->fresh()->status);
            $this->assertSame('unpaid', $order->fresh()->payment_status);
        }
    }

    public function test_order_messages_follow_revisions_and_status_changes_without_removing_latest_or_other_orders(): void
    {
        config(['platform.domain' => 'localhost']);
        $token = str_repeat('r', 40);
        $store = $this->shop($token);
        $order = $this->order($store);
        $other = $this->order($store);
        $this->withToken($token);
        $url = '/api/internal/bot/qris-messages';
        $context = ['kind' => 'order', 'revision' => 'none', 'order_status' => 'awaiting_payment', 'payment_status' => 'unpaid'];
        $old = $this->postJson($url, [...$this->payload($order, 'RECEIPT'), ...$context])->assertOk()->json('data.id');
        $this->postJson($url, [...$this->payload($other, 'OTHER'), ...$context])->assertOk();
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(0, 'qris_revocations');
        $order->update(['status' => 'success', 'payment_status' => 'paid']);
        $notice = CustomerNotice::withoutEvents(fn () => CustomerNotice::create(['store_id' => $store->id, 'order_id' => $order->id, 'event' => 'order:'.$order->id.':success', 'phone' => $order->phone, 'message' => 'Done']));
        $context = ['kind' => 'order', 'revision' => $notice->id, 'order_status' => 'success', 'payment_status' => 'paid'];
        $latest = $this->postJson($url, [...$this->payload($order, 'LATEST'), ...$context])->assertOk()->json('data.id');
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(1, 'qris_revocations')->assertJsonPath('qris_revocations.0.id', $old);
        $this->postJson($url.'/'.$latest.'/revoked')->assertUnprocessable();
        $this->postJson($url.'/'.$old.'/revoked')->assertOk();
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(0, 'qris_revocations');
        // A corrected result can generate a new notice even if the status stays successful.
        CustomerNotice::withoutEvents(fn () => CustomerNotice::create(['store_id' => $store->id, 'order_id' => $order->id, 'event' => 'order:'.$order->id.':success:correction', 'phone' => $order->phone, 'message' => 'Corrected']));
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(1, 'qris_revocations')->assertJsonPath('qris_revocations.0.id', $latest);
        // Registration arriving after a state change must also be withdrawn.
        $this->postJson($url, [...$this->payload($order, 'LATE'), ...$context])->assertOk();
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(2, 'qris_revocations');
        $this->assertSame('success', $order->fresh()->status);
    }

    public function test_final_notice_offers_main_menu_only_for_active_bot_packages(): void
    {
        config(['platform.domain' => 'localhost']);
        $token = str_repeat('b', 40);
        $store = $this->shop($token);
        $order = $this->order($store);
        $order->update(['status' => 'success', 'payment_status' => 'paid']);
        CustomerNotice::withoutEvents(fn () => CustomerNotice::create(['store_id' => $store->id, 'order_id' => $order->id, 'event' => 'order:'.$order->id.':success', 'phone' => $order->phone, 'message' => 'Success']));
        $this->withToken($token)->getJson('/api/internal/bot/notices')->assertOk()->assertJsonPath('data.0.commerce_enabled', true);
        $store->update(['category' => 'web']);
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonPath('data.0.commerce_enabled', false);
        $store->update(['category' => 'bot']);
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonPath('data.0.commerce_enabled', true);
        $order->update(['purpose' => 'topup']);
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonPath('data.0.commerce_enabled', true);
        foreach (['failed', 'expired', 'pending'] as $status) {
            $order->update(['status' => $status]);
            CustomerNotice::where('order_id', $order->id)->firstOrFail()->update(['event' => 'order:'.$order->id.':'.$status]);
            $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonPath('data.0.commerce_enabled', $status !== 'pending');
        }
    }

    public function test_late_registration_after_payment_and_lid_are_supported_but_other_store_phone_and_foreign_keys_are_rejected(): void
    {
        config(['platform.domain' => 'localhost']);
        $token = str_repeat('u', 40);
        $store = $this->shop($token);
        $order = $this->order($store);
        $order->update(['payment_status' => 'paid', 'status' => 'success']);
        $payload = $this->payload($order);
        $payload['key']['remoteJid'] = '10000000001@lid';
        $id = $this->withToken($token)->postJson('/api/internal/bot/qris-messages', $payload)->assertOk()->json('data.id');
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonPath('qris_revocations.0.key.remoteJid', '10000000001@lid');
        $this->postJson('/api/internal/bot/qris-messages', [...$payload, 'phone' => '6280000000000'])->assertNotFound();
        $bad = $this->payload($order);
        $bad['key']['fromMe'] = false;
        $this->postJson('/api/internal/bot/qris-messages', $bad)->assertUnprocessable();
        $bad['key']['fromMe'] = true;
        $bad['key']['remoteJid'] = '6280000000000@s.whatsapp.net';
        $this->postJson('/api/internal/bot/qris-messages', $bad)->assertUnprocessable();
        $otherToken = str_repeat('v', 40);
        $this->shop($otherToken);
        $this->withToken($otherToken)->postJson('/api/internal/bot/qris-messages', $payload)->assertNotFound();
        $this->postJson('/api/internal/bot/qris-messages/'.$id.'/revoked')->assertNotFound();
        $this->getJson('/api/internal/bot/notices')->assertOk()->assertJsonCount(0, 'qris_revocations');
    }
}
