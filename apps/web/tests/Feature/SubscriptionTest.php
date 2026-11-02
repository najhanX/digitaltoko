<?php

namespace Tests\Feature;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private string $gatewayStatus = 'PENDING';

    private ?string $gatewayPaidAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['inertia.ssr.enabled' => false]);
        config(['platform.domain' => 'localhost', 'services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('x', 40), 'cache.default' => 'array']);
        Http::preventStrayRequests();
        Http::fake(['https://hanshop.test/*' => function ($r) {
            return Http::response(['status' => 'SUCCESS', 'data' => ['invoice' => [
                'invoice_id' => $r['invoice_id'], 'id_user' => $r['id_user'], 'amount' => $r['amount'], 'total' => $r['amount'] + 123, 'id_payment' => 'DT'.$r['invoice_id'],
                'status' => $this->gatewayStatus, 'qr_url' => 'https://example.test/qr.png', 'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'paid_at' => $this->gatewayStatus === 'PAID' ? ($this->gatewayPaidAt ?? now()->toIso8601String()) : null]]]);
        }]);
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.$u->id, 'phone_verified_at' => now(), 'email_verified_at' => now()])->save();

        return $u;
    }

    private function store(User $owner, string $category = 'full'): Store
    {
        $store = Store::create(['owner_id' => $owner->id, 'slug' => 'toko-'.Store::count(), 'name' => 'Toko Test', 'category' => $category, 'plan' => 'orbit', 'status' => 'draft', 'template' => 'aurora']);
        Cache::forever('bot:'.$store->id.':state', ['status' => 'CONNECTED']);

        return $store;
    }

    private function plan(string $category = 'full', string $plan = 'galaxy'): PlanOption
    {
        return PlanOption::create(['name' => 'Paket Uji', 'category' => $category, 'plan' => $plan, 'price' => 50000, 'duration_days' => 30, 'active' => true]);
    }

    private function service(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    public function test_paid_first_invoice_waits_for_activation_and_repeated_sync_does_not_extend(): void
    {
        $s = $this->store($this->owner());
        $p = $this->plan();
        $i = $this->service()->checkout($s, $p);
        $this->assertSame('draft', $s->fresh()->status);
        $this->assertSame(50123, $i->total);
        $p->update(['price' => 90000]);
        $this->gatewayStatus = 'PAID';
        $this->service()->sync($i);
        $this->assertSame('provisioning', $s->fresh()->status);
        $this->assertNull($s->fresh()->expires_at);
        $this->service()->activate($s);
        $expiry = $s->fresh()->expires_at;
        $this->service()->activate($s);
        $this->service()->sync($i->fresh());
        $this->assertTrue($expiry->equalTo($s->fresh()->expires_at));
        $this->assertSame(50000, $s->fresh()->subscription['price']);
        $this->assertSame('applied', $i->fresh()->status);
    }

    public function test_pending_legacy_invoice_can_still_be_paid_and_activated_after_offer_is_retired(): void
    {
        $store = $this->store($this->owner());
        $offer = $this->plan();
        $invoice = $this->service()->checkout($store, $offer);
        $snapshot = [...$invoice->snapshot, 'plan' => 'orbit', 'name' => 'Mars lama'];
        $invoice->update(['snapshot' => $snapshot]);
        $offer->update(['active' => false, 'plan' => 'orbit']);
        $this->gatewayStatus = 'PAID';
        $this->service()->sync($invoice->fresh());
        $this->service()->activate($store->fresh());
        $this->assertSame('active', $store->fresh()->status);
        $this->assertSame('galaxy', $store->fresh()->plan);
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
        $this->assertSame(50000, $store->fresh()->subscription['price']);
        $this->assertContains('stock', $store->fresh()->subscription['features']);
        $this->assertContains('otomax', $store->fresh()->subscription['features']);
    }

    public function test_active_and_expired_renewal_use_correct_base_and_replay_is_idempotent(): void
    {
        $s = $this->store($this->owner());
        $s->update(['status' => 'active', 'starts_at' => now()->subDays(20), 'expires_at' => now()->addDays(10)]);
        $base = $s->expires_at;
        $this->gatewayStatus = 'PAID';
        $i = $this->service()->checkout($s, $this->plan());
        $this->assertTrue($s->fresh()->expires_at->equalTo($base->addDays(30)));
        $this->service()->sync($i);
        $this->assertTrue($s->fresh()->expires_at->equalTo($base->addDays(30)));
        $s->update(['expires_at' => now()->subDays(12), 'status' => 'expired']);
        $i = $this->service()->checkout($s, $this->plan());
        $this->assertTrue($s->fresh()->expires_at->equalTo($i->paid_at->addDays(30)));
    }

    public function test_legacy_scheduled_invoice_keeps_its_original_start_but_grants_both_channels(): void
    {
        $s = $this->store($this->owner());
        $expiry = now()->startOfSecond()->addDays(5);
        $s->update(['status' => 'active', 'starts_at' => now()->subDays(25), 'expires_at' => $expiry]);
        $snapshot = $this->plan('web')->snapshot();
        $invoice = SubscriptionInvoice::create(['store_id' => $s->id, 'owner_id' => $s->owner_id, 'snapshot' => $snapshot, 'purpose' => 'downgrade', 'status' => 'paid', 'paid_at' => now()]);
        $this->service()->tick($s);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->travelTo($expiry->copy()->addSecond());
        $this->service()->tick($s);
        $this->assertSame('full', $s->fresh()->category);
        $this->assertTrue($s->fresh()->expires_at->equalTo($expiry->addDays(30)));
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
    }

    public function test_new_trial_route_is_removed_and_panel_does_not_offer_trial(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'bot');
        $this->actingAs($owner)->post('/stores/'.$store->id.'/subscription/trial')->assertNotFound();
        $this->get('/stores/'.$store->id.'/subscription')->assertInertia(fn (AssertableInertia $page) => $page->missing('trialAvailable'));
        $this->assertSame('draft', $store->fresh()->status);
        $this->assertFalse($store->fresh()->is_trial);
        $this->assertDatabaseCount('subscription_invoices', 0);
    }

    public function test_unstarted_legacy_trial_cannot_activate_or_pair_and_returns_to_purchase(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'bot');
        $owner->forceFill(['trial_store_id' => $store->id])->save();
        $store->update(['status' => 'provisioning', 'is_trial' => true, 'trial_requested_at' => now()->subDays(2), 'ready_at' => now()->subDays(2), 'branding' => ['name' => 'Keep settings']]);
        Cache::put('bot:'.$store->id.':state', ['status' => 'CONNECTED'], 90);
        $this->assertFalse($store->canConnectBot());
        try {
            $this->service()->activate($store);
            $this->fail('Old trial must not activate');
        } catch (ValidationException) {
        }
        $this->assertNull($store->fresh()->expires_at);
        $this->service()->tick($store);
        $this->service()->tick($store->fresh());
        $this->assertSame('draft', $store->fresh()->status);
        $this->assertFalse($store->fresh()->is_trial);
        $this->assertNull($store->fresh()->trial_requested_at);
        $this->assertNull($store->fresh()->ready_at);
        $this->assertNull($owner->fresh()->trial_store_id);
        $this->assertSame('Keep settings', $store->fresh()->branding['name']);
        $this->assertDatabaseCount('subscription_invoices', 0);
    }

    public function test_active_legacy_trial_is_honored_until_its_original_expiry_without_grace(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'bot');
        $expiry = now()->startOfSecond()->addDays(3);
        $store->update(['status' => 'active', 'is_trial' => true, 'starts_at' => now()->subDays(4), 'expires_at' => $expiry, 'trial_requested_at' => now()->subDays(4)]);
        $this->service()->tick($store);
        $this->assertTrue($store->fresh()->acceptsOrders());
        $this->assertTrue($store->fresh()->canConnectBot());
        $this->assertTrue($expiry->equalTo($store->fresh()->expires_at));
        $this->travelTo($expiry);
        $this->service()->tick($store->fresh());
        $this->assertSame('expired', $store->fresh()->status);
        $this->assertFalse($store->fresh()->acceptsOrders());
    }

    public function test_paid_conversion_of_legacy_trial_does_not_add_the_trial_remainder(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'bot');
        $store->update(['status' => 'active', 'plan' => 'galaxy', 'is_trial' => true, 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(6), 'trial_requested_at' => now()->subDay()]);
        $this->gatewayStatus = 'PAID';
        $invoice = $this->service()->checkout($store, $this->plan('full', 'galaxy'));
        $this->assertFalse($store->fresh()->is_trial);
        $this->assertTrue($store->fresh()->expires_at->equalTo($invoice->paid_at->addDays(30)));
    }

    public function test_auto_activation_after_24_hours_and_grace_boundary_are_per_store(): void
    {
        $owner = $this->owner();
        $s = $this->store($owner);
        $other = $this->store($owner);
        $this->gatewayStatus = 'PAID';
        $this->service()->checkout($s, $this->plan());
        $this->travel(24)->hours();
        $this->service()->tick($s);
        $this->assertSame('active', $s->fresh()->status);
        $this->assertSame('draft', $other->fresh()->status);
        $expiry = $s->fresh()->expires_at;
        $this->travelTo($expiry);
        $this->service()->tick($s);
        $this->assertSame('grace', $s->fresh()->status);
        $this->assertTrue($s->fresh()->acceptsOrders());
        $this->travelTo($expiry->addDays(7));
        $this->service()->tick($s);
        $this->assertFalse($s->fresh()->acceptsOrders());
        $this->assertSame('expired', $s->fresh()->status);
    }

    public function test_pending_invoice_retry_reuses_id_and_expired_invoice_never_activates(): void
    {
        $s = $this->store($this->owner());
        $p = $this->plan();
        $one = $this->service()->checkout($s, $p);
        $two = $this->service()->checkout($s, $p);
        $this->assertSame($one->id, $two->id);
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->gatewayStatus = 'EXPIRED';
        $this->service()->sync($one);
        $this->assertSame('expired', $one->fresh()->status);
        $this->assertSame('draft', $s->fresh()->status);
    }

    public function test_owner_and_staff_permissions_prevent_cross_store_billing_and_unverified_purchase(): void
    {
        $owner = $this->owner();
        $s = $this->store($owner);
        $p = $this->plan();
        $this->actingAs($this->owner())->get('/stores/'.$s->id.'/subscription')->assertForbidden();
        $staff = StoreStaff::create(['store_id' => $s->id, 'name' => 'Viewer', 'username' => 'viewer', 'email' => 'v@example.test', 'password' => 'password-test123', 'permissions' => ['subscription.view']]);
        $this->app['auth']->guard('web')->logout();
        $this->actingAs($staff, 'staff')->get('/stores/'.$s->id.'/subscription')->assertOk();
        $this->post('/stores/'.$s->id.'/subscription/checkout', ['plan_id' => $p->id])->assertForbidden();
        $owner->forceFill(['verification_status' => 'pending'])->save();
        $this->actingAs($owner, 'web')->post('/stores/'.$s->id.'/subscription/checkout', ['plan_id' => $p->id])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_admin_can_publish_plan_but_owner_cannot(): void
    {
        $owner = $this->owner();
        $input = ['name' => 'Bulanan', 'category' => 'full', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 30, 'active' => true];
        $this->actingAs($owner)->post('/admin/plans', $input)->assertForbidden();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->post('/admin/plans', $input)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('plan_options', ['price' => 50000, 'active' => true]);
    }

    public function test_wrong_gateway_amount_cannot_activate_and_failed_request_can_be_retried(): void
    {
        $store = $this->store($this->owner());
        $plan = $this->plan();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://hanshop.test/*' => Http::response(['status' => 'ERROR'], 503)]);
        try {
            $this->service()->checkout($store, $plan);
            $this->fail('Gateway failed');
        } catch (ValidationException) {
        }
        $invoice = SubscriptionInvoice::firstOrFail();
        $this->assertSame('pending', $invoice->status);
        $this->assertNull($store->fresh()->expires_at);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://hanshop.test/*' => Http::response(['status' => 'SUCCESS', 'data' => ['invoice' => [
            'invoice_id' => $invoice->id, 'id_user' => $store->owner->hanshop_user_id, 'amount' => 1, 'total' => 50123,
            'status' => 'PAID', 'id_payment' => 'DT'.$invoice->id, 'expires_at' => now()->addMinutes(15)->toIso8601String(), 'paid_at' => now()->toIso8601String(),
        ]]])]);
        try {
            $this->service()->sync($invoice);
            $this->fail('Wrong amount accepted');
        } catch (ValidationException) {
        }
        $this->assertSame('pending', $invoice->fresh()->status);
        $this->assertNull($store->fresh()->expires_at);
    }

    public function test_staff_can_renew_only_their_current_package_and_cannot_change_it(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $store->update(['status' => 'active', 'expires_at' => now()->addDays(5)]);
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Billing', 'username' => 'billing', 'email' => 'b@example.test', 'password' => 'password-test123', 'permissions' => ['subscription.view', 'subscription.renew']]);
        $this->actingAs($staff, 'staff')->post('/stores/'.$store->id.'/subscription/checkout', ['plan_id' => $this->plan('bot', 'galaxy')->id])->assertForbidden();
        $this->post('/stores/'.$store->id.'/subscription/checkout', ['plan_id' => $this->plan()->id])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('subscription_invoices', 1);
        $this->assertSame('renew', SubscriptionInvoice::first()->purpose);
        $this->post('/stores/'.$store->id.'/subscription/activate')->assertForbidden();
    }
}
