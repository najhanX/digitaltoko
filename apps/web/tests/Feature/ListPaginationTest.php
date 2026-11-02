<?php

namespace Tests\Feature;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ListPaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array']);
        Http::preventStrayRequests();
    }

    private function owner(string $name = 'Owner Uji'): User
    {
        $owner = User::factory()->create(['name' => $name]);
        $owner->forceFill(['verification_status' => 'approved', 'phone_verified_at' => now(), 'email_verified_at' => now()])->save();

        return $owner;
    }

    private function store(User $owner, string $name = 'Toko Uji', string $status = 'draft'): Store
    {
        return Store::create(['owner_id' => $owner->id, 'name' => $name, 'slug' => 'uji-'.Store::count(), 'category' => 'web', 'plan' => 'orbit', 'template' => 'aurora', 'status' => $status,
            'expires_at' => $status === 'active' ? now()->addDays(30) : null]);
    }

    private function invoice(Store $store, string $status = 'applied', string $name = 'Paket Uji'): SubscriptionInvoice
    {
        return SubscriptionInvoice::create(['owner_id' => $store->owner_id, 'store_id' => $store->id, 'status' => $status, 'purpose' => 'initial',
            'snapshot' => ['name' => $name, 'category' => 'web', 'plan' => 'orbit', 'price' => 50000, 'duration_days' => 30], 'total' => 50123, 'expires_at' => now()->addHour()]);
    }

    public function test_owner_store_pages_are_bounded_filtered_and_tenant_scoped(): void
    {
        $owner = $this->owner();
        for ($i = 0; $i < 26; $i++) {
            $this->store($owner, 'Toko '.$i, $i === 0 ? 'active' : 'draft');
        }
        $other = $this->store($this->owner(), 'Toko Rahasia', 'active');
        $this->actingAs($owner)->get('/owner/stores?per_page=5')->assertInertia(fn (Assert $page) => $page
            ->has('stores', 5)->where('pagination.total', 26)->where('pagination.last_page', 6)->where('counts.stores', 26)->where('counts.active', 1)->has('workspace.stores', 20));
        $this->get('/owner/stores?per_page=5&page=6')->assertInertia(fn (Assert $page) => $page->has('stores', 1)->where('pagination.current_page', 6));
        $this->get('/owner/stores?status=active&q=Toko')->assertInertia(fn (Assert $page) => $page->has('stores', 1)->where('pagination.total', 1)->where('counts.stores', 26));
        $this->get('/owner/stores?q=Rahasia')->assertInertia(fn (Assert $page) => $page->has('stores', 0)->where('pagination.total', 0));
        $this->get('/owner/stores/'.$other->id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_unbounded_sizes_bad_query_shapes_and_out_of_range_pages_are_normalized(): void
    {
        $owner = $this->owner();
        for ($i = 0; $i < 12; $i++) {
            $this->store($owner);
        }
        $this->actingAs($owner)->get('/owner?per_page=999999&page=999999')->assertInertia(fn (Assert $page) => $page
            ->has('stores', 2)->where('pagination.per_page', 10)->where('pagination.current_page', 2));
        $this->get('/owner?q[]=bad&status[]=active&page[]=2&per_page[]=50')->assertInertia(fn (Assert $page) => $page
            ->has('stores', 10)->where('filters.q', '')->where('filters.status', '')->where('pagination.current_page', 1));
        $this->get('/owner?q=%25')->assertInertia(fn (Assert $page) => $page->has('stores', 0));
        $this->get('/owner?category=bot')->assertInertia(fn (Assert $page) => $page->has('stores', 0)->where('counts.stores', 12));
    }

    public function test_sidebar_keeps_a_selected_store_outside_its_bounded_initial_options(): void
    {
        $owner = $this->owner();
        for ($i = 0; $i < 25; $i++) {
            $store = $this->store($owner, sprintf('Toko %02d', $i));
        }
        $this->actingAs($owner)->get('/owner/stores/'.$store->id)->assertInertia(fn (Assert $page) => $page->has('workspace.stores', 21)->where('workspace.currentStoreId', $store->id));
        $this->get('/owner/profile')->assertInertia(fn (Assert $page) => $page->where('workspace.currentStoreId', $store->id));
    }

    public function test_admin_plan_and_store_filters_work_across_pages(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = $this->owner();
        for ($i = 0; $i < 11; $i++) {
            $this->store($owner);
            PlanOption::create(['name' => 'Penawaran '.$i, 'category' => 'full', 'plan' => 'galaxy', 'price' => 50000 + $i, 'duration_days' => 30, 'active' => $i < 6]);
        }
        $this->actingAs($admin)->get('/admin/plans?per_page=5&active=1&category=full&plan=galaxy')->assertInertia(fn (Assert $page) => $page->has('plans', 5)->where('pagination.total', 6));
        $this->get('/admin/plans?per_page=5&active=1&category=full&plan=galaxy&page=2')->assertInertia(fn (Assert $page) => $page->has('plans', 1)->where('plans.0.name', 'Penawaran 5'));
        $this->get('/admin?per_page=5&page=3')->assertInertia(fn (Assert $page) => $page->has('stores', 1)->where('pagination.total', 11));
        $this->get('/admin/plans?q=Penawaran%2010')->assertInertia(fn (Assert $page) => $page->has('plans', 1)->where('plans.0.active', false));
        $this->actingAs($owner)->get('/admin/plans?per_page=50')->assertForbidden();
    }

    public function test_invoice_history_keeps_current_payment_separate_and_never_exposes_other_stores(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        for ($i = 0; $i < 11; $i++) {
            $this->invoice($store, 'applied', 'Riwayat '.$i);
        }
        $current = $this->invoice($store, 'pending', 'Pembayaran Berjalan');
        $this->invoice($this->store($this->owner()), 'applied', 'Rahasia');
        $this->actingAs($owner)->get('/stores/'.$store->id.'/subscription?history_per_page=5&history_page=3')->assertInertia(fn (Assert $page) => $page
            ->has('invoices', 1)->where('historyPagination.total', 11)->where('historyPagination.current_page', 3)->where('currentInvoice.id', $current->id));
        $this->get('/stores/'.$store->id.'/subscription?history_q=Rahasia')->assertInertia(fn (Assert $page) => $page->has('invoices', 0)->where('currentInvoice.id', $current->id));
        $this->get('/stores/'.$store->id.'/subscription?history_q=Riwayat&history_status=applied')->assertInertia(fn (Assert $page) => $page->has('invoices', 10)->where('historyPagination.total', 11));
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'staff', 'email' => 'staff@uji.test', 'password' => 'test-only-password', 'permissions' => ['subscription.view'], 'active' => true]);
        $this->app['auth']->guard('web')->logout();
        $this->actingAs($staff, 'staff')->get('/stores/'.$store->id.'/subscription?history_status=applied')->assertInertia(fn (Assert $page) => $page->where('currentInvoice.id', $current->id)->where('historyPagination.total', 11));
        Http::assertNothingSent();
    }

    public function test_admin_billing_and_verification_filters_keep_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = $this->owner('Pencarian Owner');
        $store = $this->store($owner);
        for ($i = 0; $i < 7; $i++) {
            $this->invoice($store, 'applied', 'Tagihan '.$i);
        }
        $this->invoice($store, 'expired', 'Invoice Lama');
        $this->actingAs($admin)->get('/admin/billing?status=applied&q=Tagihan&per_page=5&page=2')->assertInertia(fn (Assert $page) => $page->has('invoices.data', 2)->where('invoices.total', 7));
        $this->get('/admin/verifications?q=Pencarian&status=approved&per_page=5')->assertInertia(fn (Assert $page) => $page->has('owners.data', 1)->where('owners.total', 1));
        $this->get('/admin/verifications?status=rejected')->assertInertia(fn (Assert $page) => $page->has('owners.data', 0));
    }
}
