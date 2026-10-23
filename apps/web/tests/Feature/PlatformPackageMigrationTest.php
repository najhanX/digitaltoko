<?php

namespace Tests\Feature;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\CatalogAccess;
use Database\Seeders\PackagePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlatformPackageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transition_preserves_prices_durations_invoice_snapshots_and_store_dates(): void
    {
        $owner = User::factory()->create();
        $old = PlanOption::create(['name' => 'Mercury', 'category' => 'bot', 'plan' => 'space', 'price' => 10000, 'duration_days' => 7, 'active' => true]);
        $full = PlanOption::create(['name' => 'Jupiter · 90 hari', 'category' => 'bot', 'plan' => 'galaxy', 'price' => 123456, 'duration_days' => 90, 'active' => true]);
        $snapshot = [...$old->snapshot(), 'features' => ['bot', 'stock', 'hanshop']];
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Legacy', 'slug' => 'legacy', 'category' => 'bot', 'plan' => 'space', 'status' => 'active', 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(5), 'subscription' => $snapshot]);
        $invoice = SubscriptionInvoice::create(['owner_id' => $owner->id, 'store_id' => $store->id, 'snapshot' => $snapshot, 'purpose' => 'initial', 'status' => 'applied']);
        $dates = $store->only(['starts_at', 'expires_at', 'status']);
        $migration = require database_path('migrations/2026_10_23_003547_consolidate_platform_subscription_packages.php');
        $migration->up();
        $migration->up();
        $this->seed(PackagePlanSeeder::class);
        $this->assertFalse($old->fresh()->active);
        $this->assertSame('Bot WhatsApp · 90 hari', $full->fresh()->name);
        $this->assertSame(123456, $full->fresh()->price);
        $this->assertSame(90, $full->fresh()->duration_days);
        $this->assertTrue($full->fresh()->active);
        $this->assertEquals($dates, $store->fresh()->only(['starts_at', 'expires_at', 'status']));
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
        $this->assertSame($snapshot['price'], $store->fresh()->subscription['price']);
        $this->assertSame('galaxy', $store->fresh()->plan);
        foreach (['digiflazz', 'stock', 'hanshop', 'otomax'] as $source) {
            $this->assertTrue(CatalogAccess::supports($store->fresh(), $source));
        }
        $this->assertFalse($store->fresh()->supportsWeb());
        $this->assertSame(2, $store->fresh()->subscription['features_version']);
    }

    public function test_legacy_snapshot_does_not_restrict_sources_but_new_checkout_rejects_old_offer(): void
    {
        $owner = User::factory()->create(['verification_status' => 'approved', 'email_verified_at' => now(), 'phone_verified_at' => now(), 'hanshop_user_id' => 'TEST']);
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Legacy', 'slug' => 'legacy', 'category' => 'web', 'plan' => 'orbit', 'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addDay(), 'subscription' => ['features' => ['web', 'digiflazz']]]);
        foreach (['digiflazz', 'stock', 'hanshop', 'otomax'] as $source) {
            $this->assertTrue(CatalogAccess::supports($store, $source));
        }
        $service = app(SubscriptionService::class);
        $offer = PlanOption::create(['name' => 'Old', 'category' => 'web', 'plan' => 'orbit', 'price' => 10000, 'duration_days' => 7, 'active' => true]);
        $this->expectException(ValidationException::class);
        $service->checkout($store, $offer);
    }
}
