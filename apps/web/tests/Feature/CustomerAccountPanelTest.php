<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerCommerce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class CustomerAccountPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array', 'suppliers.cache_store' => 'array']);
        Http::preventStrayRequests();
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner'])->id, 'name' => 'Account test', 'slug' => Str::lower(Str::random(10)), 'category' => 'full', 'plan' => 'galaxy', 'template' => 'nebula', 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function customer(Store $store): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => 'Customer test', 'phone' => '6281'.random_int(10000000, 99999999), 'phone_verified_at' => now(), 'email' => Str::random(8).'@example.test', 'password' => 'test-password-123', 'balance' => 50000]);
    }

    private function base(Store $store): string
    {
        return 'http://'.$store->slug.'.localhost';
    }

    public function test_profile_tab_returns_only_current_customer_and_safe_header_identity(): void
    {
        $store = $this->shop();
        $customer = $this->customer($store);
        $this->actingAs($customer, 'customer')->get($this->base($store).'/account?tab=profile&q=test')->assertInertia(fn (Page $page) => $page
            ->where('tab', 'profile')->where('customer.id', $customer->id)->where('customer.balance', 50000)->where('customer.has_password', true)
            ->missing('customer.password')->has('storeCustomer', 4)->where('storeCustomer.id', $customer->id)->where('storeCustomer.name', $customer->name)->where('storeCustomer.balance', 50000)->where('storeCustomer.can_topup', true)->has('items', 0));
    }

    public function test_header_wallet_follows_store_environment_and_topup_availability(): void
    {
        $store = $this->shop();
        $customer = $this->customer($store);
        $customer->update(['sandbox_balance' => 12000]);
        $this->actingAs($customer, 'customer')->get($this->base($store).'/account?tab=wallet&topup=1')
            ->assertInertia(fn (Page $page) => $page->where('storeCustomer.balance', 50000)->where('storeCustomer.can_topup', true));
        $store->update(['sandbox' => true]);
        $this->get($this->base($store).'/account?tab=wallet&topup=1')
            ->assertInertia(fn (Page $page) => $page->where('customer.balance', 12000)->where('storeCustomer.balance', 12000));
        app(CustomerCommerce::class)->settings($store)->update(['closing' => true]);
        $this->get($this->base($store).'/account?tab=wallet&topup=1')
            ->assertInertia(fn (Page $page) => $page->where('storeCustomer.can_topup', false)->where('accepting', false));
        $this->assertSame(50000, $customer->fresh()->balance);
    }

    public function test_header_identity_is_scoped_by_store_and_not_shown_to_guests(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $this->get($this->base($store).'/account/login')->assertInertia(fn (Page $page) => $page->where('storeCustomer', null));
        $this->actingAs($this->customer($store), 'customer')->get($this->base($other).'/account/login')->assertInertia(fn (Page $page) => $page->where('storeCustomer', null));
        $this->get($this->base($other).'/account?tab=profile')->assertRedirect('/account/login');
    }

    public function test_wallet_filter_pagination_and_environment_keep_other_customer_data_out(): void
    {
        $store = $this->shop();
        $customer = $this->customer($store);
        $other = $this->customer($store);
        foreach ([$customer, $other] as $person) {
            foreach ([false, true] as $sandbox) {
                CustomerLedger::create(['store_id' => $store->id, 'customer_id' => $person->id, 'event' => (string) Str::uuid(), 'reason' => 'topup', 'amount' => 50000, 'balance_after' => 50000, 'is_sandbox' => $sandbox]);
            }
        }
        $this->actingAs($customer, 'customer')->get($this->base($store).'/account?tab=wallet&q=topup&per_page=5')->assertInertia(fn (Page $page) => $page
            ->where('tab', 'wallet')->where('pagination.total', 1)->where('pagination.per_page', 5)->has('items', 1)->where('items.0.reason', 'topup'));
    }

    public function test_customer_logout_clears_customer_guard_and_account_requires_login(): void
    {
        $store = $this->shop();
        $this->actingAs($this->customer($store), 'customer')->post($this->base($store).'/account/logout')->assertRedirect('/account/login');
        $this->assertGuest('customer');
        $this->get($this->base($store).'/account')->assertRedirect('/account/login');
    }
}
