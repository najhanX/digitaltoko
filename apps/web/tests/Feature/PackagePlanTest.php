<?php

namespace Tests\Feature;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\PackageCatalog;
use Database\Seeders\PackagePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PackagePlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_single_package_includes_both_channels_and_all_sources(): void
    {
        $this->seed(PackagePlanSeeder::class);
        $expected = [['Web Topup + Bot WhatsApp', 'full', 'galaxy']];
        $this->assertDatabaseCount('plan_options', 1);
        $this->assertSame($expected, array_map(fn ($item) => [$item['name'], $item['category'], $item['plan']], PackageCatalog::all()));
        foreach ($expected as [$name,$category,$source]) {
            $offer = PlanOption::where('category', $category)->where('plan', $source)->firstOrFail();
            $this->assertSame($name.' · 30 hari', $offer->name);
            $this->assertFalse($offer->active);
            $features = app(SubscriptionService::class)->features($category, $source);
            $this->assertContains('hanshop', $features);
            $this->assertSame(true, in_array('bot', $features));
            $this->assertSame(true, in_array('web', $features));
            $this->assertSame($source !== 'space', in_array('digiflazz', $features));
            $this->assertSame($source !== 'orbit', in_array('stock', $features));
        }
        Http::assertNothingSent();
    }

    public function test_seed_renames_legacy_offers_without_resetting_commercial_terms_or_historical_subscriptions(): void
    {
        $offer = PlanOption::create(['name' => 'Demo Galaxy BOT 90 hari', 'category' => 'bot', 'plan' => 'galaxy', 'price' => 123456, 'duration_days' => 90, 'active' => true]);
        $other = PlanOption::create(['name' => 'Promo khusus toko', 'category' => 'bot', 'plan' => 'galaxy', 'price' => 654321, 'duration_days' => 365, 'active' => false]);
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved']);
        $snapshot = $offer->snapshot();
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Toko existing', 'slug' => 'existing-package', 'category' => 'bot', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addDays(90), 'subscription' => $snapshot]);
        $invoice = SubscriptionInvoice::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'snapshot' => $snapshot, 'purpose' => 'initial', 'status' => 'applied']);
        $expiry = $store->expires_at;
        $this->seed(PackagePlanSeeder::class);
        $this->seed(PackagePlanSeeder::class);
        $this->assertDatabaseCount('plan_options', 3);
        $this->assertSame('Demo Galaxy BOT 90 hari', $offer->fresh()->name);
        $this->assertSame(123456, $offer->fresh()->price);
        $this->assertSame(90, $offer->fresh()->duration_days);
        $this->assertFalse($offer->fresh()->active);
        $this->assertSame('Promo khusus toko', $other->fresh()->name);
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
        $this->assertSame($snapshot, $store->fresh()->subscription);
        $this->assertTrue($expiry->equalTo($store->fresh()->expires_at));
        Http::assertNothingSent();
    }

    public function test_admin_only_lists_current_bundle_offers(): void
    {
        $this->seed(PackagePlanSeeder::class);
        PlanOption::create(['name' => 'Retired', 'category' => 'bot', 'plan' => 'galaxy', 'price' => 10000, 'duration_days' => 7, 'active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/plans')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('plans', 1)->where('plans.0.category', 'full')->where('plans.0.name', 'Web Topup + Bot WhatsApp · 30 hari'));
    }
}
