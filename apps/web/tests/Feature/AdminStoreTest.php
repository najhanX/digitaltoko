<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreAdminEvent;
use App\Models\StoreStaff;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\CatalogAccess;
use App\Support\HanshopIdentity;
use App\Support\PackageCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AdminStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    private function store(?User $owner = null, array $attributes = []): Store
    {
        $owner ??= User::factory()->create(['role' => 'owner']);

        return Store::create([...['owner_id' => $owner->id, 'name' => 'Toko contoh', 'slug' => 'shop-'.fake()->unique()->numerify('######'), 'category' => 'full', 'plan' => 'galaxy', 'template' => 'aurora', 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(20)], ...$attributes]);
    }

    public function test_only_platform_admin_can_read_and_change_stores(): void
    {
        $store = $this->store();
        $this->get('/admin/stores')->assertRedirect('/admin/login');
        $this->actingAs($store->owner)->get('/admin/stores')->assertForbidden();
        $this->get('/admin/stores/'.$store->id)->assertForbidden();
        $this->patch('/admin/stores/'.$store->id, ['action' => 'suspend', 'reason' => 'Tes penangguhan'])->assertForbidden();
        $this->assertDatabaseCount('store_admin_events', 0);
        $this->assertNull($store->fresh()->admin_suspended_at);
    }

    public function test_staff_session_cannot_replace_admin_actor_or_notification_recipient(): void
    {
        $store = $this->store();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff contoh', 'username' => 'staff', 'email' => 'staff@example.test', 'password' => 'test-password-only', 'active' => true, 'permissions' => ['dashboard.view']]);
        $this->actingAs($staff, 'staff')->get('/admin/stores')->assertRedirect('/admin/login');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')->get('/admin/stores/'.$store->id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workspace.role', 'admin')->where('notificationSummary.guard', 'web'));
        $this->patch('/admin/stores/'.$store->id, ['action' => 'suspend', 'reason' => 'Pemeriksaan melalui akun admin'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('store_admin_events', ['store_id' => $store->id, 'admin_id' => $admin->id]);
        $this->assertDatabaseHas('panel_notifications', ['user_id' => $admin->id, 'staff_id' => null, 'title' => 'Perubahan berhasil disimpan']);
        $this->assertDatabaseHas('panel_notifications', ['staff_id' => $staff->id, 'user_id' => null, 'title' => 'Toko ditangguhkan administrator']);
    }

    public function test_listing_searches_owner_contacts_and_filters_both_package_dimensions_with_bounded_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['name' => 'Owner Sasaran']);
        $owner->forceFill(['phone' => '628123450000', 'hanshop_user_id' => 'TARGET01'])->save();
        for ($i = 0; $i < 12; $i++) {
            $this->store($owner, ['category' => 'bot', 'plan' => 'space']);
        }
        $this->store($owner, ['category' => 'web', 'plan' => 'space']);
        $this->store();
        $this->actingAs($admin)->get('/admin/stores?q=628123450000&package=mercury&per_page=5&page=2')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Stores')->has('stores', 5)->where('pagination.total', 12)->where('pagination.current_page', 2)->where('counts.total', 14)
            ->where('stores.0.category', 'bot')->where('stores.0.plan', 'space')->missing('stores.0.owner.ktp_path')->missing('stores.0.worker_token_hash'));
        $this->get('/admin/stores?owner='.$owner->id)->assertInertia(fn (AssertableInertia $page) => $page->where('pagination.total', 13)->where('selectedOwner.id', $owner->id));
        Http::assertNothingSent();
    }

    public function test_edit_has_required_reason_audit_and_notifications_without_touching_ownership_or_subscription(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $store = $this->store();
        $before = $store->only(['slug', 'owner_id', 'plan', 'category', 'expires_at']);
        $this->actingAs($admin)->patch('/admin/stores/'.$store->id, ['action' => 'edit', 'name' => 'Baru', 'template' => 'atlas', 'reason' => '   '])->assertSessionHasErrors('reason');
        $this->patch('/admin/stores/'.$store->id, ['action' => 'edit', 'name' => 'Toko diperbarui', 'template' => 'atlas', 'reason' => 'Koreksi nama atas permintaan owner', 'slug' => 'ubah-ilegal', 'owner_id' => $admin->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals($before, $store->fresh()->only(array_keys($before)));
        $event = StoreAdminEvent::firstOrFail();
        $this->assertSame($admin->id, $event->admin_id);
        $this->assertSame('Toko contoh', $event->before['name']);
        $this->assertSame('Toko diperbarui', $event->after['name']);
        $this->assertDatabaseHas('panel_notifications', ['user_id' => $store->owner_id, 'title' => 'Pengaturan toko diperbarui admin']);
    }

    public function test_suspension_stops_storefront_bot_and_catalog_only_for_that_store_and_resume_respects_expiry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $store = $this->store(null, ['worker_token_hash' => hash('sha256', str_repeat('a', 40))]);
        $other = $this->store($store->owner);
        $expiry = $store->expires_at;
        $this->actingAs($admin)->patch('/admin/stores/'.$store->id, ['action' => 'suspend', 'reason' => 'Pemeriksaan keluhan pelanggan'])->assertSessionHasNoErrors();
        $this->assertFalse($store->fresh()->acceptsOrders());
        $this->assertFalse($store->fresh()->canConnectBot());
        $this->assertTrue($other->fresh()->acceptsOrders());
        $this->assertTrue($expiry->equalTo($store->fresh()->expires_at));
        $this->withToken(str_repeat('a', 40))->getJson('/api/internal/bot/access')->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.can_connect', false);
        $this->get('http://'.$store->slug.'.localhost')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', false)->has('products', 0));
        $this->actingAs($store->owner)->post('http://localhost/stores/'.$store->id.'/products', [])->assertForbidden();
        $this->actingAs($admin)->patch('/admin/stores/'.$store->id, ['action' => 'suspend', 'reason' => 'Permintaan ulang'])->assertSessionHasErrors('action');
        $this->travel(30)->days();
        $this->patch('/admin/stores/'.$store->id, ['action' => 'resume', 'reason' => 'Pemeriksaan telah diselesaikan'])->assertSessionHasNoErrors();
        $this->assertNull($store->fresh()->admin_suspended_at);
        $this->assertSame('expired', $store->fresh()->status);
        $this->assertFalse($store->fresh()->acceptsOrders());
        $this->assertDatabaseCount('store_admin_events', 2);
    }

    public function test_expiry_correction_preserves_snapshot_and_rejects_trial_or_unsettled_invoice(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $store = $this->store(null, ['subscription' => ['name' => 'Penawaran lama', 'price' => 90000]]);
        $this->actingAs($admin)->patch('/admin/stores/'.$store->id, ['action' => 'expiry', 'expires_at' => now()->addDays(40)->toIso8601String(), 'reason' => 'Kompensasi gangguan layanan'])->assertSessionHasNoErrors();
        $this->assertSame(90000, $store->fresh()->subscription['price']);
        $this->assertSame(40, (int) now()->startOfDay()->diffInDays($store->fresh()->expires_at->startOfDay()));
        $this->assertDatabaseCount('subscription_invoices', 0);
        SubscriptionInvoice::create(['owner_id' => $store->owner_id, 'store_id' => $store->id, 'snapshot' => [], 'purpose' => 'downgrade', 'status' => 'paid']);
        $this->patch('/admin/stores/'.$store->id, ['action' => 'expiry', 'expires_at' => now()->addDays(50)->toIso8601String(), 'reason' => 'Koreksi kedua kalinya'])->assertSessionHasErrors('expires_at');
        $trial = $this->store(null, ['is_trial' => true]);
        $this->patch('/admin/stores/'.$trial->id, ['action' => 'expiry', 'expires_at' => now()->addDays(50)->toIso8601String(), 'reason' => 'Koreksi trial ditolak'])->assertSessionHasErrors('expires_at');
        $this->assertDatabaseCount('store_admin_events', 1);
    }

    public function test_paid_invoice_does_not_remove_administrative_suspension(): void
    {
        $store = $this->store(null, ['admin_suspended_at' => now(), 'admin_suspension_reason' => 'Sedang diperiksa']);
        $store->owner->forceFill(['hanshop_user_id' => 'HDTEST'])->save();
        $invoice = SubscriptionInvoice::create(['owner_id' => $store->owner_id, 'store_id' => $store->id, 'status' => 'pending', 'purpose' => 'renew',
            'snapshot' => ['name' => 'Andromeda', 'price' => 100000, 'duration_days' => 30, 'category' => 'full', 'plan' => 'galaxy']]);
        $this->mock(HanshopIdentity::class)->shouldReceive('call')->once()->andReturn(['invoice' => ['invoice_id' => $invoice->id, 'id_user' => 'HDTEST', 'amount' => 100000, 'id_payment' => 'PAYTEST', 'total' => 100123, 'status' => 'PAID', 'expires_at' => now()->addMinutes(10)->toIso8601String(), 'paid_at' => now()->toIso8601String()]]);
        app(SubscriptionService::class)->sync($invoice);
        $this->assertSame('applied', $invoice->fresh()->status);
        $this->assertNotNull($store->fresh()->admin_suspended_at);
        $this->assertFalse($store->fresh()->acceptsOrders());
    }

    public function test_admin_can_switch_to_each_package_and_entitlements_change_without_resetting_commercial_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        foreach (PackageCatalog::all() as $package) {
            $oldCategory = $package['category'] === 'full' ? 'bot' : 'full';
            $snapshot = ['name' => 'Penawaran dibayar', 'category' => $oldCategory, 'plan' => 'galaxy', 'price' => 95000, 'duration_days' => 30, 'features' => ['bot', 'web', 'digiflazz', 'stock', 'hanshop']];
            $store = $this->store(null, ['category' => $oldCategory, 'subscription' => $snapshot, 'admin_suspended_at' => now(), 'admin_suspension_reason' => 'Tetap diperiksa']);
            $other = $this->store($store->owner);
            $invoice = SubscriptionInvoice::create(['owner_id' => $store->owner_id, 'store_id' => $store->id, 'snapshot' => $snapshot, 'purpose' => 'initial', 'status' => 'applied']);
            $unchanged = $store->only(['starts_at', 'expires_at', 'status', 'is_trial', 'owner_id', 'slug', 'admin_suspended_at', 'admin_suspension_reason']);
            $this->patch('/admin/stores/'.$store->id, ['action' => 'package', 'package' => $package['key'], 'reason' => 'Koreksi administratif paket toko'])->assertRedirect()->assertSessionHasNoErrors();
            $store->refresh();
            $this->assertEquals($unchanged, $store->only(array_keys($unchanged)));
            $this->assertSame($package['category'], $store->category);
            $this->assertSame($package['plan'], $store->plan);
            $this->assertSame(95000, $store->subscription['price']);
            $this->assertSame(30, $store->subscription['duration_days']);
            $this->assertSame($snapshot, $invoice->fresh()->snapshot);
            $this->assertTrue($other->fresh()->acceptsOrders());
            $this->assertFalse($store->acceptsOrders());
            $this->assertSame($package['plan'] !== 'space', CatalogAccess::supports($store, 'digiflazz'));
            $this->assertSame($package['plan'] !== 'orbit', CatalogAccess::supports($store, 'stock'));
            $this->assertTrue(CatalogAccess::supports($store, 'hanshop'));
            $this->assertSame($package['category'] !== 'bot', $store->supportsWeb());
            $this->assertSame($package['name'], StoreAdminEvent::where('store_id', $store->id)->firstOrFail()->after['package_name']);
            $this->assertDatabaseHas('panel_notifications', ['user_id' => $store->owner_id, 'title' => 'Paket toko diubah administrator']);
        }
        $this->assertDatabaseCount('subscription_invoices', 9);
        Http::assertNothingSent();
    }

    public function test_package_change_blocks_trial_open_invoices_invalid_selection_and_non_admins(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $store = $this->store();
        $input = ['action' => 'package', 'package' => 'mercury', 'reason' => 'Koreksi paket oleh administrator'];
        $this->actingAs($store->owner)->patch('/admin/stores/'.$store->id, $input)->assertForbidden();
        $this->actingAs($admin)->patch('/admin/stores/'.$store->id, [...$input, 'package' => 'unknown'])->assertSessionHasErrors('package');
        $this->patch('/admin/stores/'.$store->id, [...$input, 'package' => 'andromeda'])->assertSessionHasErrors('package');
        $this->patch('/admin/stores/'.$store->id, [...$input, 'reason' => ''])->assertSessionHasErrors('reason');
        foreach (['pending', 'paid', 'review'] as $status) {
            $blocked = $this->store();
            SubscriptionInvoice::create(['owner_id' => $blocked->owner_id, 'store_id' => $blocked->id, 'snapshot' => [], 'purpose' => 'downgrade', 'status' => $status]);
            $this->patch('/admin/stores/'.$blocked->id, $input)->assertSessionHasErrors('package');
            $this->assertSame('galaxy', $blocked->fresh()->plan);
        }
        foreach ([['is_trial' => true], ['status' => 'provisioning'], ['status' => 'purging']] as $attributes) {
            $blocked = $this->store(null, $attributes);
            $response = $this->patch('/admin/stores/'.$blocked->id, $input);
            $response->assertSessionHasErrors($blocked->status === 'purging' ? 'action' : 'package');
            $this->assertSame('galaxy', $blocked->fresh()->plan);
        }
        $this->assertDatabaseCount('store_admin_events', 0);
        Http::assertNothingSent();
    }

    public function test_changing_draft_selection_does_not_start_subscription_and_history_can_filter_package_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $store = $this->store(null, ['status' => 'draft', 'starts_at' => null, 'expires_at' => null, 'subscription' => null]);
        $this->actingAs($admin)->patch('/admin/stores/'.$store->id, ['action' => 'package', 'package' => 'venus', 'reason' => 'Perbaikan pilihan paket sebelum pembayaran'])->assertSessionHasNoErrors();
        $store->refresh();
        $this->assertSame('draft', $store->status);
        $this->assertNull($store->expires_at);
        $this->assertNull($store->subscription);
        $this->assertFalse($store->canConnectBot());
        $this->get('/admin/stores/'.$store->id.'?action=package')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events', 1)->where('events.0.action', 'package')->where('events.0.after.package_name', 'Venus')->where('filters.action', 'package'));
    }

    public function test_detail_audit_is_paginated_scoped_and_keeps_supplier_and_stock_secrets_private(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $store = $this->store();
        $other = $this->store();
        for ($i = 0; $i < 7; $i++) {
            StoreAdminEvent::create(['store_id' => $store->id, 'admin_id' => $admin->id, 'action' => 'edit', 'reason' => 'Alasan perubahan '.$i, 'before' => [], 'after' => []]);
        }
        StoreAdminEvent::create(['store_id' => $other->id, 'admin_id' => $admin->id, 'action' => 'suspend', 'reason' => 'Toko lain', 'before' => [], 'after' => []]);
        $this->actingAs($admin)->get('/admin/stores/'.$store->id.'?page=2')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/StoreDetail')->has('events', 2)->where('pagination.total', 7)->where('store.id', $store->id)->missing('store.worker_token_hash')->missing('store.owner.ktp_path')->missing('bot.qr'));
        Http::assertNothingSent();
    }
}
