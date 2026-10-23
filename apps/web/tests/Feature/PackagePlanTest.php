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

    public function test_three_platforms_include_all_sources_and_preserve_channel_boundaries(): void
    {
        $this->seed(PackagePlanSeeder::class);
        $expected = [['Bot WhatsApp', 'bot', 'galaxy'], ['Web Topup', 'web', 'galaxy'], ['Web + Bot WhatsApp', 'full', 'galaxy']];
        $this->assertDatabaseCount('plan_options', 3);
        $this->assertSame($expected, array_map(fn ($item) => [$item['name'], $item['category'], $item['plan']], PackageCatalog::all()));
        foreach ($expected as [$name,$category,$source]) {
            $offer = PlanOption::where('category', $category)->where('plan', $source)->firstOrFail();
            $this->assertSame($name.' · 30 hari', $offer->name);
            $this->assertFalse($offer->active);
            $features = app(SubscriptionService::class)->features($category, $source);
            $this->assertContains('hanshop', $features);
            $this->assertSame($category !== 'web', in_array('bot', $features));
            $this->assertSame($category !== 'bot', in_array('web', $features));
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
        $this->assertDatabaseCount('plan_options', 4);
        $this->assertSame('Bot WhatsApp · 90 hari', $offer->fresh()->name);
        $this->assertSame(123456, $offer->fresh()->price);
        $this->assertSame(90, $offer->fresh()->duration_days);
        $this->assertTrue($offer->fresh()->active);
        $this->assertSame('Promo khusus toko', $other->fresh()->name);
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
        $this->assertSame($snapshot, $store->fresh()->subscription);
        $this->assertTrue($expiry->equalTo($store->fresh()->expires_at));
        Http::assertNothingSent();
    }

    public function test_package_filters_scope_both_channel_and_source_and_admin_keeps_the_agreed_order(): void
    {
        $this->seed(PackagePlanSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/admin/plans')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('plans', 3)->where('plans.0.name', 'Bot WhatsApp · 30 hari')->where('plans.2.name', 'Web + Bot WhatsApp · 30 hari'));
        $this->get('/admin/plans?package=bot')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('plans', 1)->where('plans.0.category', 'bot')->where('plans.0.plan', 'galaxy'));
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved']);
        foreach ([['bot', 'space'], ['web', 'space'], ['bot', 'orbit']] as $i => [$category,$source]) {
            Store::create(['owner_id' => $owner->id, 'name' => 'Toko '.$i, 'slug' => 'package-'.$i, 'category' => $category, 'plan' => $source, 'status' => 'draft']);
        }
        $this->get('/admin?package=bot')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('stores', 2)->where('stores.0.category', 'bot')->where('counts.stores', 3));
        $this->actingAs($owner)->get('/owner/stores?package=bot')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('stores', 2)->where('stores.0.category', 'bot')->where('counts.stores', 3));
    }
}
