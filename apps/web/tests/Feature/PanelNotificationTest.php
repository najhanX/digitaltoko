<?php

namespace Tests\Feature;

use App\Models\PanelNotification;
use App\Models\PlanOption;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\PanelNotifications;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PanelNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'phone_verified_at' => now(), 'email_verified_at' => now(), 'hanshop_user_id' => 'HD'.Str::random(8)]);
    }

    private function store(User $owner, string $category = 'web'): Store
    {
        return Store::create(['owner_id' => $owner->id, 'name' => 'Toko Uji', 'slug' => 'uji-'.Str::lower(Str::random(8)), 'category' => $category, 'plan' => 'galaxy', 'template' => 'aurora', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
    }

    private function staff(Store $store, array $permissions = []): StoreStaff
    {
        return StoreStaff::create(['store_id' => $store->id, 'name' => 'Operator', 'username' => Str::random(10), 'email' => Str::random(10).'@example.test', 'password' => 'test-password-123', 'permissions' => $permissions]);
    }

    public function test_history_loads_in_batches_is_filtered_and_private_to_current_owner(): void
    {
        $owner = $this->owner();
        PanelNotification::factory()->count(24)->create(['user_id' => $owner->id]);
        PanelNotification::factory()->create(['user_id' => $owner->id, 'read_at' => now()]);
        $other = PanelNotification::factory()->create();
        $this->getJson('/panel/notifications')->assertUnauthorized();
        $first = $this->actingAs($owner)->getJson('/panel/notifications')->assertOk()->assertJsonCount(10, 'data.items')->assertJsonPath('data.total', 25)->assertJsonPath('data.unread_count', 24)->assertJsonMissingPath('data.items.0.user_id')->assertHeader('Cache-Control', 'no-store, private');
        $second = $this->getJson('/panel/notifications?cursor='.urlencode($first->json('data.next_cursor')))->assertOk()->assertJsonCount(10, 'data.items');
        $third = $this->getJson('/panel/notifications?cursor='.urlencode($second->json('data.next_cursor')))->assertOk()->assertJsonCount(5, 'data.items')->assertJsonPath('data.next_cursor', null);
        $ids = [...array_column($first->json('data.items'), 'id'), ...array_column($second->json('data.items'), 'id'), ...array_column($third->json('data.items'), 'id')];
        $this->assertCount(25, array_unique($ids));
        $this->getJson('/panel/notifications?filter=unread')->assertJsonPath('data.total', 24);
        $this->postJson('/panel/notifications/'.$other->id.'/read')->assertNotFound();
        $this->getJson('/panel/notifications?filter=invalid')->assertUnprocessable();
        $this->getJson('/panel/notifications?cursor=invalid')->assertUnprocessable();
        $this->getJson('/panel/notifications?cursor='.urlencode(base64_encode('{"_pointsToNextItems":true}')))->assertUnprocessable();
    }

    public function test_scroll_cursor_preserves_remaining_history_when_new_notifications_arrive_or_unread_items_are_removed(): void
    {
        $owner = $this->owner();
        $original = PanelNotification::factory()->count(23)->create(['user_id' => $owner->id, 'created_at' => now()->subMinute()]);
        PanelNotification::factory()->count(4)->create();
        $first = $this->actingAs($owner)->getJson('/panel/notifications?filter=unread')->assertOk();
        $firstIds = array_column($first->json('data.items'), 'id');
        $this->postJson('/panel/notifications/'.$firstIds[9].'/read')->assertOk()->assertJsonPath('data.unread_count', 22);
        $new = PanelNotification::factory()->create(['user_id' => $owner->id]);
        $second = $this->getJson('/panel/notifications?filter=unread&cursor='.urlencode($first->json('data.next_cursor')))->assertOk()->assertJsonCount(10, 'data.items');
        $third = $this->getJson('/panel/notifications?filter=unread&cursor='.urlencode($second->json('data.next_cursor')))->assertOk()->assertJsonCount(3, 'data.items')->assertJsonPath('data.next_cursor', null);
        $ids = [...$firstIds, ...array_column($second->json('data.items'), 'id'), ...array_column($third->json('data.items'), 'id')];
        $this->assertCount(23, array_unique($ids));
        $this->assertEqualsCanonicalizing($original->modelKeys(), $ids);
        $this->assertNotContains($new->id, $ids);
        $this->getJson('/panel/notifications?filter=unread')->assertJsonPath('data.items.0.id', $new->id);
        $this->postJson('/panel/notifications/read-all')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson('/panel/notifications?filter=unread')->assertJsonCount(0, 'data.items')->assertJsonPath('data.next_cursor', null);
    }

    public function test_staff_history_is_separate_from_owner_and_other_local_accounts(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $staff = $this->staff($store, ['web.manage']);
        $other = $this->staff($store);
        $service = app(PanelNotifications::class);
        $service->send($owner, 'Owner', 'Pesan owner', 'info', '/owner');
        $mine = $service->send($staff, 'Staff', 'Pesan staff', 'info', '/staff/'.$store->id.'/settings', $store);
        $foreign = $service->send($other, 'Staff lain', 'Pesan staff lain', 'info', null, $store);
        $this->actingAs($staff, 'staff')->getJson('/panel/notifications?guard=staff')->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $mine->id)->assertJsonPath('data.recipient', 'staff:'.$staff->id);
        $this->postJson('/panel/notifications/'.$foreign->id.'/read', ['guard' => 'staff'])->assertNotFound();
        $staff->update(['permissions' => []]);
        $this->getJson('/panel/notifications?guard=staff')->assertJsonPath('data.items.0.url', null);
        $staff->update(['active' => false]);
        $this->getJson('/panel/notifications?guard=staff')->assertUnauthorized();
    }

    public function test_admin_only_receives_its_own_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        PanelNotification::factory()->create(['user_id' => $admin->id]);
        PanelNotification::factory()->count(3)->create();
        $this->actingAs($admin)->getJson('/panel/notifications')->assertJsonPath('data.total', 1);
        $this->get('/admin')->assertInertia(fn (Assert $p) => $p->where('notificationSummary.recipient', 'user:'.$admin->id));
    }

    public function test_read_and_read_all_do_not_change_other_accounts_and_read_is_idempotent(): void
    {
        $owner = $this->owner();
        $mine = PanelNotification::factory()->create(['user_id' => $owner->id]);
        PanelNotification::factory()->create(['user_id' => $owner->id]);
        $other = PanelNotification::factory()->create();
        $this->actingAs($owner)->postJson('/panel/notifications/'.$mine->id.'/read')->assertOk();
        $time = $mine->fresh()->read_at;
        $this->travel(1)->minutes();
        $this->postJson('/panel/notifications/'.$mine->id.'/read')->assertOk();
        $this->assertTrue($time->equalTo($mine->fresh()->read_at));
        $this->postJson('/panel/notifications/read-all')->assertOk();
        $this->getJson('/panel/notifications')->assertJsonPath('data.unread_count', 0);
        $this->assertNull($other->fresh()->read_at);
        $this->assertDatabaseCount('panel_notifications', 3);
    }

    public function test_action_creates_durable_history_and_flash_is_delivered_only_once(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $url = '/owner/stores/'.$store->id.'/manage';
        $this->actingAs($owner)->from($url)->patch($url, ['name' => 'Nama baru', 'template' => 'aurora'])->assertSessionHasNoErrors()->assertSessionHas('success')->assertInertiaFlash('panelNotice.title', 'Perubahan berhasil disimpan');
        $this->assertDatabaseCount('panel_notifications', 1);
        $notification = PanelNotification::firstOrFail();
        $this->assertSame($store->id, $notification->store_id);
        $this->get($url)->assertInertia(fn (Assert $p) => $p->hasFlash('panelNotice.id', $notification->id)->where('notificationSummary.unread_count', 1));
        $this->get($url)->assertInertia(fn (Assert $p) => $p->missingFlash('panelNotice'));
        $this->getJson('/panel/notifications')->assertJsonPath('data.items.0.id', $notification->id);
        $this->assertDatabaseCount('panel_notifications', 1);
    }

    public function test_validation_failure_records_generic_warning_without_input_or_credentials(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->actingAs($owner)->patch('/owner/stores/'.$store->id.'/manage', ['name' => '', 'template' => 'invalid', 'password' => 'private-test-secret'])->assertSessionHasErrors('name');
        $notice = PanelNotification::firstOrFail();
        $this->assertSame('warning', $notice->level);
        $this->assertStringNotContainsString('private-test-secret', json_encode($notice->toArray()));
        $this->assertSame('Periksa isian formulir', $notice->title);
    }

    public function test_staff_action_is_saved_only_in_that_staff_history(): void
    {
        $store = $this->store($this->owner());
        $staff = $this->staff($store, ['web.manage']);
        $this->actingAs($staff, 'staff')->patch('/staff/'.$store->id.'/settings', ['name' => 'Nama staff', 'template' => 'aurora'])->assertSessionHasNoErrors();
        $notification = PanelNotification::firstOrFail();
        $this->assertSame($staff->id, $notification->staff_id);
        $this->assertNull($notification->user_id);
        $this->assertSame($store->id, $notification->store_id);
    }

    public function test_grace_and_expiry_notifications_are_not_duplicated_by_scheduler_ticks(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->staff($store, ['subscription.view']);
        $store->update(['expires_at' => now()->subDay()]);
        $service = app(SubscriptionService::class);
        $service->tick($store);
        $service->tick($store->fresh());
        $this->assertDatabaseCount('panel_notifications', 2);
        $this->travel(7)->days();
        $service->tick($store->fresh());
        $service->tick($store->fresh());
        $this->assertDatabaseCount('panel_notifications', 4);
    }

    public function test_bot_heartbeats_do_not_duplicate_notifications_or_save_pairing_secrets(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'bot');
        $token = str_repeat('a', 64);
        $store->update(['worker_token_hash' => hash('sha256', $token), 'bot_phone' => '6281234567890', 'bot_method' => 'code', 'bot_connection_id' => (string) Str::uuid()]);
        $body = ['status' => 'PAIRING', 'pairing_code' => 'ABCDEFGH', 'expires_at' => now()->addMinute()->toIso8601String(), 'connection_id' => $store->bot_connection_id];
        $this->withToken($token)->postJson('/api/internal/bot/events', $body)->assertOk();
        $this->assertDatabaseCount('panel_notifications', 0);
        $body = ['status' => 'CONNECTED', 'phone' => $store->bot_phone, 'connection_id' => $store->bot_connection_id];
        $this->postJson('/api/internal/bot/events', $body)->assertOk();
        $this->postJson('/api/internal/bot/events', $body)->assertOk();
        $this->assertDatabaseCount('panel_notifications', 1);
        $saved = json_encode(PanelNotification::firstOrFail()->toArray());
        $this->assertStringNotContainsString('ABCDEFGH', $saved);
        $this->assertStringNotContainsString('6281234567890', $saved);
    }

    public function test_paid_invoice_emits_one_event_despite_repeated_payment_checks(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('x', 40)]);
        Http::fake(['https://hanshop.test/*' => fn ($request) => Http::response(['status' => 'SUCCESS', 'data' => ['invoice' => [
            'invoice_id' => $request['invoice_id'], 'id_user' => $request['id_user'], 'amount' => $request['amount'], 'total' => $request['amount'] + 123, 'id_payment' => 'DT'.$request['invoice_id'],
            'status' => 'PAID', 'qr_url' => 'https://example.test/qr.png', 'expires_at' => now()->addMinutes(15)->toIso8601String(), 'paid_at' => now()->toIso8601String(),
        ]]])]);
        $owner = $this->owner();
        $store = $this->store($owner);
        $store->update(['status' => 'draft', 'expires_at' => null]);
        $plan = PlanOption::create(['name' => 'Uji', 'category' => 'web', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $service = app(SubscriptionService::class);
        $invoice = $service->checkout($store, $plan);
        $service->sync($invoice->fresh());
        $service->sync($invoice->fresh());
        $this->assertDatabaseCount('panel_notifications', 1);
        $this->assertSame('Pembayaran sewa diterima', PanelNotification::firstOrFail()->title);
    }

    public function test_store_and_staff_deletion_cascades_their_history_but_preserves_global_account_notice(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $staff = $this->staff($store);
        $service = app(PanelNotifications::class);
        $service->send($owner, 'Global', 'Profil berhasil diperbarui');
        $service->send($owner, 'Toko', 'Data toko diperbarui', 'info', null, $store);
        $service->send($staff, 'Staff', 'Data diperbarui', 'info', null, $store);
        $store->delete();
        $this->assertDatabaseCount('panel_notifications', 1);
        $this->assertSame('Global', PanelNotification::firstOrFail()->title);
    }
}
