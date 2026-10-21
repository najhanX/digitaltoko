<?php

namespace Tests\Feature;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PanelWorkflowTest extends TestCase
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
        $owner = User::factory()->create();
        $owner->forceFill(['role' => 'owner', 'verification_status' => 'approved', 'hanshop_user_id' => 'HD'.$owner->id,
            'phone_verified_at' => now(), 'email_verified_at' => now()])->save();

        return $owner;
    }

    private function store(User $owner): Store
    {
        return Store::create(['owner_id' => $owner->id, 'name' => 'Toko Uji', 'slug' => 'toko-'.Store::count(),
            'category' => 'web', 'plan' => 'orbit', 'template' => 'aurora', 'status' => 'draft']);
    }

    private function staff(Store $store, array $permissions): StoreStaff
    {
        return StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff Uji', 'username' => 'operator',
            'email' => 'operator@example.test', 'password' => 'test-password-123', 'permissions' => $permissions, 'active' => true]);
    }

    public function test_owner_navigation_context_is_consistent_and_does_not_expose_other_stores(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $other = $this->store($this->owner());
        $this->actingAs($owner);
        foreach (['/owner', '/owner/stores', '/owner/profile', '/owner/stores/create', '/owner/stores/'.$store->id.'/manage', '/stores/'.$store->id.'/subscription'] as $path) {
            $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('workspace.role', 'owner')->where('workspace.viewer.name', $owner->name)
                ->where('workspace.canCreate', true)->has('workspace.stores', 1)->where('workspace.stores.0.id', $store->id));
        }
        $this->get('/stores/'.$store->id.'/subscription')->assertInertia(fn (Assert $page) => $page->where('workspace.currentStoreId', $store->id));
        $this->get('/owner/stores/'.$other->id.'/manage')->assertNotFound();
        $this->get('/stores/'.$other->id.'/subscription')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_create_store_redirects_to_package_selection_without_starting_rent(): void
    {
        $this->actingAs($this->owner())->post('/owner/stores', ['name' => 'Toko Baru', 'slug' => 'toko-baru',
            'category' => 'bot', 'plan' => 'orbit', 'template' => 'aurora'])->assertSessionHasNoErrors()
            ->assertRedirect('/stores/'.Store::firstOrFail()->id.'/subscription');
        $store = Store::firstOrFail();
        $this->assertSame('draft', $store->status);
        $this->assertNull($store->expires_at);
        $this->assertFalse($store->acceptsOrders());
        Http::assertNothingSent();
    }

    public function test_selected_owner_store_remains_in_navigation_across_global_pages(): void
    {
        $owner = $this->owner();
        $a = $this->store($owner);
        $b = $this->store($owner);
        $this->actingAs($owner)->get('/owner/stores/'.$b->id)->assertInertia(fn (Assert $page) => $page->where('workspace.currentStoreId', $b->id));
        foreach (['/owner', '/owner/profile', '/owner/stores/create'] as $path) {
            $this->get($path)->assertInertia(fn (Assert $page) => $page->where('workspace.currentStoreId', $b->id));
        }
        $b->delete();
        $this->get('/owner')->assertInertia(fn (Assert $page) => $page->where('workspace.currentStoreId', $a->id));
    }

    public function test_approved_ktp_with_unverified_contact_does_not_offer_store_creation(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['phone_verified_at' => null])->save();
        $this->actingAs($owner)->get('/owner')->assertInertia(fn (Assert $page) => $page->where('workspace.canCreate', false));
        $this->get('/owner/stores/create')->assertForbidden();
        $this->post('/owner/stores', ['name' => 'Toko Baru', 'slug' => 'toko-baru', 'category' => 'bot', 'plan' => 'orbit', 'template' => 'aurora'])->assertForbidden();
        $this->assertDatabaseCount('stores', 0);
    }

    public function test_staff_navigation_and_settings_remain_scoped_to_store_and_permission(): void
    {
        $store = $this->store($this->owner());
        $other = $this->store($this->owner());
        $staff = $this->staff($store, ['dashboard.view', 'subscription.view', 'web.manage']);
        $this->actingAs($staff, 'staff');
        foreach (['/staff/'.$store->id.'/dashboard', '/stores/'.$store->id.'/subscription', '/staff/'.$store->id.'/settings'] as $path) {
            $this->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('workspace.role', 'staff')->has('workspace.stores', 1)->where('workspace.currentStoreId', $store->id)
                ->where('workspace.canCreate', false)->where('workspace.viewer.name', $staff->name));
        }
        $this->patch('/staff/'.$store->id.'/settings', ['name' => 'Nama Diperbarui', 'template' => 'atlas', 'status' => 'active', 'expires_at' => now()->addYears(10)])
            ->assertSessionHasNoErrors();
        $this->assertSame('Nama Diperbarui', $store->fresh()->name);
        $this->assertSame('draft', $store->fresh()->status);
        $this->assertNull($store->fresh()->expires_at);
        $this->patch('/staff/'.$other->id.'/settings', ['name' => 'Unauthorized', 'template' => 'atlas'])->assertForbidden();
        $this->get('/staff/'.$other->id.'/settings')->assertForbidden();
        $staff->update(['permissions' => ['dashboard.view']]);
        $this->get('/staff/'.$store->id.'/settings')->assertForbidden();
        $this->get('/stores/'.$store->id.'/subscription')->assertForbidden();
    }

    public function test_permission_to_renew_also_includes_reading_subscription(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $this->actingAs($owner)->post('/owner/stores/'.$store->id.'/staff', ['name' => 'Billing Staff', 'username' => 'billing',
            'email' => 'billing@example.test', 'password' => 'strong-test-password', 'active' => true, 'permissions' => ['subscription.renew']])->assertSessionHasNoErrors();
        $permissions = StoreStaff::firstOrFail()->permissions;
        $this->assertContains('subscription.renew', $permissions);
        $this->assertContains('subscription.view', $permissions);
    }

    public function test_staff_login_logout_and_expired_session_use_the_correct_role_pages(): void
    {
        $store = $this->store($this->owner());
        $this->get('/staff/'.$store->id.'/settings')->assertRedirect('/staff/'.$store->id.'/login');
        $staff = $this->staff($store, ['web.manage']);
        $this->post('/staff/'.$store->id.'/login', ['identifier' => $staff->username, 'password' => 'test-password-123'])
            ->assertRedirect('/staff/'.$store->id.'/settings');
        $this->post('/logout')->assertRedirect('/staff/'.$store->id.'/login');
        $staff->update(['permissions' => ['subscription.view']]);
        $this->post('/staff/'.$store->id.'/login', ['identifier' => $staff->username, 'password' => 'test-password-123'])
            ->assertRedirect('/stores/'.$store->id.'/subscription');
        $this->post('/logout');
        $staff->update(['permissions' => []]);
        $this->post('/staff/'.$store->id.'/login', ['identifier' => $staff->username, 'password' => 'test-password-123'])
            ->assertRedirect('/staff/'.$store->id.'/access');
        $this->get('/staff/'.$store->id.'/access')->assertOk();
        $this->get('/staff/'.$store->id.'/dashboard')->assertForbidden();
    }

    public function test_package_choices_explain_the_same_change_that_billing_will_apply(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $store->update(['status' => 'active', 'plan' => 'galaxy', 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(20)]);
        PlanOption::create(['name' => 'Orbit', 'category' => 'web', 'plan' => 'orbit', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        PlanOption::create(['name' => 'Galaxy', 'category' => 'web', 'plan' => 'galaxy', 'price' => 100000, 'duration_days' => 30, 'active' => true]);
        PlanOption::create(['name' => 'Nonaktif', 'category' => 'web', 'plan' => 'space', 'price' => 10000, 'duration_days' => 30, 'active' => false]);
        $this->actingAs($owner)->get('/stores/'.$store->id.'/subscription')->assertInertia(fn (Assert $page) => $page
            ->has('plans', 2)->where('plans.0.purpose', 'downgrade')->where('plans.1.purpose', 'renew'));
        $store->update(['is_trial' => true]);
        $staff = $this->staff($store, ['subscription.view', 'subscription.renew']);
        $this->app['auth']->guard('web')->logout();
        $this->actingAs($staff, 'staff')->get('/stores/'.$store->id.'/subscription')
            ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
    }

    public function test_paying_before_trial_activation_removes_trial_label_and_unused_reservation(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('x', 40)]);
        Http::fake(['https://hanshop.test/*' => fn ($request) => Http::response(['status' => 'SUCCESS', 'data' => ['invoice' => [
            'invoice_id' => $request['invoice_id'], 'id_user' => $request['id_user'], 'amount' => $request['amount'],
            'total' => $request['amount'] + 123, 'id_payment' => 'DT'.$request['invoice_id'], 'status' => 'PAID',
            'expires_at' => now()->addMinutes(15)->toIso8601String(), 'paid_at' => now()->toIso8601String(),
        ]]])]);
        $owner = $this->owner();
        $store = $this->store($owner);
        $store->update(['category' => 'bot']);
        $service = app(SubscriptionService::class);
        $owner->forceFill(['trial_store_id' => $store->id])->save();
        $store->update(['status' => 'provisioning', 'is_trial' => true, 'trial_requested_at' => now()]);
        $plan = PlanOption::create(['name' => 'Galaxy Bot', 'category' => 'bot', 'plan' => 'galaxy', 'price' => 100000, 'duration_days' => 30, 'active' => true]);
        $service->checkout($store, $plan);
        $this->assertFalse($store->fresh()->is_trial);
        $this->assertNull($store->fresh()->trial_requested_at);
        $this->assertNull($store->fresh()->expires_at);
        $this->assertNull($owner->fresh()->trial_store_id);
        $this->assertNull($owner->fresh()->trial_used_at);
    }
}
