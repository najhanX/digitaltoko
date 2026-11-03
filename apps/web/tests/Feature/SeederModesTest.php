<?php

namespace Tests\Feature;

use App\Models\CatalogProduct;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\PlanOption;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentActivitySeeder;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\ProductionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SeederModesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['platform.admin' => ['name' => 'Configured Admin', 'email' => 'admin@example.test', 'password' => 'Admin-production-test-123!']]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function seedMode(string $seeder): void
    {
        $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])->assertSuccessful();
    }

    public function test_production_default_seeds_only_foundation_and_preserves_admin_prices_on_rerun(): void
    {
        $this->app->instance('env', 'production');
        $this->seedMode(DatabaseSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test', 'role' => 'admin']);
        foreach (['stores', 'store_staff', 'customers', 'catalog_products', 'supplier_connections', 'customer_orders', 'customer_ledgers', 'subscription_invoices'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $plan = PlanOption::sole();
        $this->assertFalse($plan->active);
        $this->assertSame('full', $plan->category);
        $plan->update(['name' => 'Harga Pilihan Admin', 'price' => 89000, 'duration_days' => 45, 'active' => true]);
        DB::table('commerce_options')->where('id', 'custom_qris_price')->update(['value' => 200000]);
        $this->seedMode(ProductionSeeder::class);
        $this->assertDatabaseCount('plan_options', 1);
        $this->assertSame(89000, $plan->fresh()->price);
        $this->assertSame(45, $plan->fresh()->duration_days);
        $this->assertTrue($plan->fresh()->active);
        $this->assertSame('Harga Pilihan Admin', $plan->fresh()->name);
        $this->assertSame(200000, (int) DB::table('commerce_options')->where('id', 'custom_qris_price')->value('value'));
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_production_requires_admin_configuration_before_writing_foundation(): void
    {
        config(['platform.admin.email' => '']);
        try {
            $this->seedMode(ProductionSeeder::class);
            $this->fail('Missing admin accepted');
        } catch (ValidationException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('plan_options', 0);
            $this->assertDatabaseCount('commerce_options', 0);
        }
    }

    public function test_development_default_has_many_sandbox_records_and_reseeding_preserves_edits(): void
    {
        $this->app->instance('env', 'development');
        $this->seedMode(DatabaseSeeder::class);
        foreach (['stores' => 8, 'store_staff' => 21, 'catalog_products' => 240, 'premium_stocks' => 160, 'customers' => 100,
            'subscription_invoices' => 24, 'customer_orders' => 160, 'order_attempts' => 80, 'commerce_receipts' => 80, 'customer_ledgers' => 100, 'plan_options' => 5] as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertSame(5, PlanOption::where('active', true)->count());
        $this->assertSame(0, Store::where('sandbox', false)->count());
        $this->assertSame(0, CustomerOrder::where('is_sandbox', false)->count());
        $this->assertSame(0, CatalogProduct::whereNull('category_id')->count());
        $this->assertSame(0, CustomerOrder::whereNotIn('status', ['success', 'failed', 'cancelled', 'expired'])->count());
        $this->assertSame(0, DB::table('subscription_invoices')->whereNotIn('status', ['applied', 'expired'])->count());
        $this->assertDatabaseCount('supplier_connections', 0);
        $this->assertDatabaseCount('customer_notices', 0);
        $this->assertSame(0, (int) Customer::sum('balance'));
        $customer = Customer::firstOrFail();
        $customer->update(['name' => 'Nama edit', 'sandbox_balance' => 70000]);
        $order = CustomerOrder::firstOrFail();
        $order->update(['message' => 'Catatan diubah']);
        $plan = PlanOption::where('duration_days', 7)->firstOrFail();
        $plan->update(['price' => 19000, 'active' => false]);
        $this->seedMode(DevelopmentSeeder::class);
        $this->assertDatabaseCount('subscription_invoices', 24);
        $this->assertDatabaseCount('customer_orders', 160);
        $this->assertDatabaseCount('customer_ledgers', 100);
        $this->assertDatabaseCount('users', 4);
        $this->assertSame('Nama edit', $customer->fresh()->name);
        $this->assertSame(70000, $customer->fresh()->sandbox_balance);
        $this->assertSame('Catatan diubah', $order->fresh()->message);
        $this->assertSame(19000, $plan->fresh()->price);
        $this->assertFalse($plan->fresh()->active);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_development_is_blocked_in_production_and_staging_before_creating_admin(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app->instance('env', $environment);
            foreach ([DevelopmentSeeder::class, DevelopmentActivitySeeder::class] as $seeder) {
                try {
                    $this->seedMode($seeder);
                    $this->fail('Development allowed in '.$environment);
                } catch (\RuntimeException) {
                    $this->assertDatabaseCount('users', 0);
                    $this->assertDatabaseCount('stores', 0);
                }
            }
        }
    }

    public function test_unknown_environment_defaults_to_production_foundation(): void
    {
        $this->app->instance('env', 'staging');
        $this->seedMode(DatabaseSeeder::class);
        $this->assertSame('admin', User::sole()->role);
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('customer_orders', 0);
    }
}
