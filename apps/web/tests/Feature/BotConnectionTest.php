<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BotConnectionTest extends TestCase
{
    use RefreshDatabase;

    private bool $managerFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'cache.default' => 'array', 'inertia.ssr.enabled' => false,
            'services.bot_manager.url' => 'http://127.0.0.1:3099', 'services.bot_manager.token' => str_repeat('m', 64)]);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:3099/*' => fn () => $this->managerFails ? Http::failedConnection() : Http::response(['status' => 'SUCCESS'])]);
    }

    private function store(string $status = 'provisioning', string $category = 'bot'): Store
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved']);

        return Store::create(['owner_id' => $owner->id, 'slug' => 'bot-'.Str::lower(Str::random(8)), 'name' => 'Toko Bot',
            'category' => $category, 'plan' => 'galaxy', 'status' => $status, 'expires_at' => $status === 'active' ? now()->addDays(30) : null]);
    }

    private function url(Store $store, string $suffix = ''): string
    {
        return 'http://localhost/stores/'.$store->id.'/bot'.$suffix;
    }

    private function staff(Store $store, array $permissions): StoreStaff
    {
        return StoreStaff::create(['store_id' => $store->id, 'name' => 'Operator', 'username' => Str::random(10),
            'email' => Str::random(12).'@example.test', 'password' => 'test-password', 'permissions' => $permissions]);
    }

    public function test_owner_can_choose_code_and_normalized_phone_without_exposing_worker_token(): void
    {
        $store = $this->store();
        $this->actingAs($store->owner)->from($this->url($store))->post($this->url($store, '/connect'), ['phone' => '0812-3456-7890', 'method' => 'code'])->assertRedirect($this->url($store))->assertSessionHasNoErrors();
        $store->refresh();
        $this->assertSame('6281234567890', $store->bot_phone);
        $this->assertSame('code', $store->bot_method);
        $this->assertNotNull($store->bot_connection_id);
        Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1:3099/stores/'.$store->id.'/connect'
            && $r['phone'] === '6281234567890' && hash('sha256', $r['worker_token']) === $store->worker_token_hash);
        $this->get($this->url($store))->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $p) => $p->component('Owner/BotConnection')->where('canManage', true)->where('store.bot_phone', '6281234567890')->missing('store.worker_token_hash'));
        $this->assertNull($store->expires_at);
    }

    public function test_foreign_owner_and_staff_cannot_view_or_connect_another_store(): void
    {
        $store = $this->store();
        $other = $this->store();
        $this->actingAs($other->owner)->get($this->url($store))->assertForbidden();
        $this->post($this->url($store, '/connect'), ['phone' => '6281234567890', 'method' => 'qr'])->assertForbidden();
        $this->actingAs($this->staff($other, ['bot.manage']), 'staff')->get($this->url($store))->assertForbidden();
        $this->post($this->url($store, '/disconnect'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_view_only_staff_cannot_see_pairing_secrets_or_change_connection(): void
    {
        $store = $this->store();
        Cache::put('bot:'.$store->id.':state', ['status' => 'PAIRING', 'qr_image' => 'data:image/png;base64,YQ==', 'pairing_code' => 'ABCDEFGH', 'qr' => 'private', 'expires_at' => now()->addMinute()->toIso8601String()], 90);
        $staff = $this->staff($store, ['bot.view']);
        $this->actingAs($staff, 'staff')->get($this->url($store))->assertInertia(fn (Assert $p) => $p->where('canManage', false)->where('connection.status', 'PAIRING')->missing('connection.qr_image')->missing('connection.pairing_code')->missing('connection.qr'));
        $this->getJson($this->url($store).'-state')->assertJsonMissingPath('data.qr_image')->assertJsonMissingPath('data.pairing_code');
        $this->post($this->url($store, '/connect'), ['phone' => '6281234567890', 'method' => 'qr'])->assertForbidden();
        $this->post($this->url($store, '/disconnect'))->assertForbidden();
        $staff->update(['active' => false]);
        $this->get($this->url($store))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_managing_staff_can_connect_and_disconnect_only_its_store(): void
    {
        $store = $this->store();
        $this->actingAs($this->staff($store, ['bot.manage']), 'staff')->post($this->url($store, '/connect'), ['phone' => '+62 81234567890', 'method' => 'qr'])->assertSessionHasNoErrors();
        $this->assertNotNull($store->fresh()->worker_token_hash);
        $store->update(['ready_at' => now()]);
        $this->post($this->url($store, '/disconnect'))->assertSessionHasNoErrors();
        $this->assertNull($store->fresh()->worker_token_hash);
        $this->assertNull($store->fresh()->ready_at);
        Http::assertSentCount(2);
    }

    public function test_draft_expired_and_suspended_stores_cannot_start_a_worker(): void
    {
        foreach (['draft', 'expired', 'suspended', 'purging'] as $status) {
            $store = $this->store($status);
            $this->actingAs($store->owner)->post($this->url($store, '/connect'), ['phone' => '6281234567890', 'method' => 'qr'])->assertSessionHasErrors('connection');
            $this->assertNull($store->fresh()->worker_token_hash);
        }
        Http::assertNothingSent();
    }

    public function test_paid_grace_accepts_connection_but_exact_seventh_day_and_trial_expiry_do_not(): void
    {
        $this->travelTo(now()->startOfSecond());
        $store = $this->store('active');
        $store->update(['status' => 'grace', 'expires_at' => now()->subDays(6)]);
        $this->assertTrue($store->canConnectBot());
        $store->update(['expires_at' => now()->subDays(7)]);
        $this->assertFalse($store->canConnectBot());
        $store->update(['is_trial' => true, 'expires_at' => now()]);
        $this->assertFalse($store->canConnectBot());
    }

    public function test_invalid_phone_or_method_never_dispatches_to_manager(): void
    {
        $store = $this->store();
        $this->actingAs($store->owner)->post($this->url($store, '/connect'), ['phone' => '123', 'method' => 'qr'])->assertSessionHasErrors('phone');
        $this->post($this->url($store, '/connect'), ['phone' => '6281234567890', 'method' => 'shell'])->assertSessionHasErrors('method');
        Http::assertNothingSent();
    }

    public function test_manager_configuration_and_transport_failure_are_recoverable(): void
    {
        $store = $this->store();
        config(['services.bot_manager.token' => '']);
        $this->actingAs($store->owner)->post($this->url($store, '/connect'), ['phone' => '6281234567890', 'method' => 'qr'])->assertSessionHasErrors('connection');
        Http::assertNothingSent();
        config(['services.bot_manager.token' => str_repeat('m', 64)]);
        $this->managerFails = true;
        $this->post($this->url($store, '/connect'), ['phone' => '6281234567890', 'method' => 'qr'])->assertSessionHasErrors('connection');
        $this->assertSame('ERROR', Cache::get('bot:'.$store->id.':state')['status']);
        $this->managerFails = false;
        $this->get($this->url($store))->assertOk();
        $this->post($this->url($store, '/connect'), ['phone' => '6281234567890', 'method' => 'code'])->assertSessionHasNoErrors();
    }

    public function test_old_attempts_and_wrong_linked_phone_cannot_activate_store(): void
    {
        $store = $this->store();
        $token = str_repeat('a', 64);
        $id = (string) Str::uuid();
        $store->update(['worker_token_hash' => hash('sha256', $token), 'bot_connection_id' => $id, 'bot_phone' => '6281234567890', 'bot_method' => 'qr']);
        $this->withToken($token)->postJson('http://localhost/api/internal/bot/events', ['status' => 'CONNECTED', 'phone' => $store->bot_phone, 'connection_id' => (string) Str::uuid()])->assertStatus(409);
        $this->postJson('http://localhost/api/internal/bot/events', ['status' => 'CONNECTED', 'phone' => '6289999999999', 'connection_id' => $id])->assertUnprocessable();
        $this->assertFalse(app(SubscriptionService::class)->ready($store));
        $this->postJson('http://localhost/api/internal/bot/events', ['status' => 'CONNECTED', 'phone' => $store->bot_phone, 'connection_id' => $id])->assertOk();
        $this->assertTrue(app(SubscriptionService::class)->ready($store));
        $store->update(['bot_connection_id' => (string) Str::uuid()]);
        $this->assertFalse(app(SubscriptionService::class)->ready($store->fresh()));
    }

    public function test_pairing_expiry_and_connected_event_clear_secrets(): void
    {
        $this->travelTo(now()->startOfSecond());
        $store = $this->store();
        $token = str_repeat('a', 64);
        $store->update(['worker_token_hash' => hash('sha256', $token), 'bot_connection_id' => (string) Str::uuid(), 'bot_phone' => '6281234567890', 'bot_method' => 'code']);
        $event = ['status' => 'PAIRING', 'pairing_code' => 'ABCDEFGH', 'qr_image' => 'data:image/png;base64,YQ==', 'connection_id' => $store->bot_connection_id, 'expires_at' => now()->addSeconds(30)->toIso8601String()];
        $this->withToken($token)->postJson('http://localhost/api/internal/bot/events', $event)->assertOk();
        $this->assertArrayNotHasKey('qr_image', Cache::get('bot:'.$store->id.':state'));
        $this->actingAs($store->owner)->getJson($this->url($store).'-state')->assertJsonPath('data.pairing_code', 'ABCDEFGH');
        $this->travel(30)->seconds();
        $this->getJson($this->url($store).'-state')->assertJsonPath('data.status', 'EXPIRED')->assertJsonMissingPath('data.pairing_code');
        $this->withToken($token)->postJson('http://localhost/api/internal/bot/events', $event)->assertUnprocessable();
        $this->postJson('http://localhost/api/internal/bot/events', [...$event, 'status' => 'CONNECTED', 'phone' => $store->bot_phone])->assertOk();
        $this->assertArrayNotHasKey('pairing_code', Cache::get('bot:'.$store->id.':state'));
        $this->travel(91)->seconds();
        $this->assertFalse(app(SubscriptionService::class)->ready($store));
    }

    public function test_concurrent_command_cannot_rotate_another_in_flight_attempt(): void
    {
        $store = $this->store();
        $lock = Cache::lock('bot:'.$store->id.':command', 15);
        $lock->get();
        try {
            $this->actingAs($store->owner)->post($this->url($store, '/connect'), ['phone' => '6281234567890', 'method' => 'qr'])->assertSessionHasErrors('connection');
            $this->assertNull($store->fresh()->bot_connection_id);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_staff_with_only_bot_permission_lands_on_connection_after_login(): void
    {
        $store = $this->store();
        $staff = $this->staff($store, ['bot.view']);
        $this->post('http://localhost/staff/'.$store->id.'/login', ['identifier' => $staff->username, 'password' => 'test-password'])
            ->assertRedirect('/stores/'.$store->id.'/bot');
    }

    public function test_worker_limit_is_per_store_even_when_workers_share_the_vps_ip(): void
    {
        $first = $this->store();
        $second = $this->store();
        $firstToken = str_repeat('a', 64);
        $secondToken = str_repeat('b', 64);
        $first->update(['worker_token_hash' => hash('sha256', $firstToken)]);
        $second->update(['worker_token_hash' => hash('sha256', $secondToken)]);
        for ($i = 0; $i < 120; $i++) {
            $this->withToken($firstToken)->getJson('http://localhost/api/internal/bot/access')->assertOk();
        }
        $this->getJson('http://localhost/api/internal/bot/access')->assertStatus(429);
        $this->withToken($secondToken)->getJson('http://localhost/api/internal/bot/access')->assertOk();
    }
}
