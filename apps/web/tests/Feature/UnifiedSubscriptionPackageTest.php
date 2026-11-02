<?php

namespace Tests\Feature;

use App\Models\DiscountVoucher;
use App\Models\PlanOption;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\PackageCatalog;
use Database\Seeders\PackagePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UnifiedSubscriptionPackageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array', 'services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('x', 40)]);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function owner(): User
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'phone_verified_at' => now(), 'email_verified_at' => now()]);
        $owner->forceFill(['hanshop_user_id' => 'HD'.$owner->id])->save();

        return $owner;
    }

    private function offer(string $category = 'full', int $days = 30, int $price = 90000): PlanOption
    {
        return PlanOption::create(['category' => $category, 'plan' => 'galaxy', 'name' => PackageCatalog::offerName($category, 'galaxy', $days), 'duration_days' => $days, 'price' => $price, 'active' => true]);
    }

    private function paidGateway(): void
    {
        Http::fake(['https://hanshop.test/*' => fn ($request) => Http::response(['status' => 'SUCCESS', 'data' => ['invoice' => [
            'invoice_id' => $request['invoice_id'], 'id_user' => $request['id_user'], 'amount' => $request['amount'], 'total' => $request['amount'] + 123,
            'id_payment' => 'DT'.$request['invoice_id'], 'status' => 'PAID', 'expires_at' => now()->addMinutes(15)->toIso8601String(), 'paid_at' => now()->toIso8601String(), 'qr_url' => null,
        ]]])]);
    }

    public function test_catalog_and_public_prices_only_offer_the_bundle_at_existing_full_package_prices(): void
    {
        $full = $this->offer();
        $long = $this->offer('full', 90, 240000);
        $old = $this->offer('web', 30, 20000);
        $this->seed(PackagePlanSeeder::class);
        $this->seed(PackagePlanSeeder::class);
        $this->assertSame(['full'], PackageCatalog::keys());
        $this->assertFalse($old->fresh()->active);
        $this->assertSame(90000, $full->fresh()->price);
        $this->assertSame(240000, $long->fresh()->price);
        $this->get('/paket')->assertOk()->assertInertia(fn (Assert $page) => $page->has('offers', 2)->where('offers.0.category', 'full')->where('offers.1.duration_days', 90));
        // Even a stale producer that reactivates an old offer cannot publish it.
        $old->update(['active' => true]);
        $this->get('/paket')->assertInertia(fn (Assert $page) => $page->has('offers', 2));
        Http::assertNothingSent();
    }

    public function test_owner_creation_always_creates_both_channels_and_rejects_legacy_selection(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/owner/stores', ['name' => 'Toko Terpadu', 'slug' => 'toko-terpadu', 'template' => 'aurora'])->assertRedirect();
        $shop = Store::where('slug', 'toko-terpadu')->firstOrFail();
        $this->assertSame('full', $shop->category);
        $this->assertTrue($shop->supportsWeb());
        $this->assertTrue($shop->supportsBot());
        $this->assertNull($shop->expires_at);
        $this->post('/owner/stores', ['name' => 'Manipulasi', 'slug' => 'legacy-choice', 'template' => 'aurora', 'category' => 'bot'])->assertSessionHasErrors('category');
        $this->assertDatabaseMissing('stores', ['slug' => 'legacy-choice']);
        Http::assertNothingSent();
    }

    public function test_admin_duration_save_rejects_old_channel_and_owner_cannot_manage_prices(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['name' => '60 hari', 'duration_days' => 60, 'price' => 170000, 'active' => true];
        $this->actingAs($admin)->post('/admin/plans', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('plan_options', [...$payload, 'category' => 'full', 'plan' => 'galaxy']);
        $this->post('/admin/plans', [...$payload, 'category' => 'web'])->assertSessionHasErrors('category');
        $this->actingAs($this->owner())->post('/admin/plans', $payload)->assertForbidden();
    }

    public function test_renewal_adds_duration_without_reset_or_downgrade_and_repeated_sync_is_idempotent(): void
    {
        $shop = Store::create(['owner_id' => $this->owner()->id, 'name' => 'Renew', 'slug' => 'renew-bundle', 'status' => 'active', 'starts_at' => now()->subDays(10), 'expires_at' => now()->addDays(20)]);
        $expiry = $shop->expires_at;
        $this->paidGateway();
        $service = app(SubscriptionService::class);
        $invoice = $service->checkout($shop, $this->offer('full', 90));
        $service->sync($invoice);
        $this->assertSame('renew', $invoice->purpose);
        $this->assertTrue($expiry->addDays(90)->equalTo($shop->fresh()->expires_at));
        $this->assertSame(['bot', 'web', 'digiflazz', 'stock', 'hanshop', 'otomax'], $shop->fresh()->subscription['features']);
        try {
            $service->checkout($shop, $this->offer('bot'));
            $this->fail('Retired channel must not be purchased');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subscription', $exception->errors());
        }
    }

    public function test_initial_activation_requires_both_address_and_whatsapp_and_legacy_paid_invoice_grants_full_access(): void
    {
        $owner = $this->owner();
        $shop = Store::create(['owner_id' => $owner->id, 'name' => 'Aktivasi', 'slug' => 'activate-bundle', 'status' => 'draft']);
        $legacy = $this->offer('web');
        $snapshot = $legacy->snapshot();
        $invoice = SubscriptionInvoice::create(['owner_id' => $owner->id, 'store_id' => $shop->id, 'purpose' => 'initial', 'snapshot' => $snapshot, 'status' => 'pending']);
        $this->paidGateway();
        $service = app(SubscriptionService::class);
        $service->sync($invoice);
        $this->assertSame('provisioning', $shop->fresh()->status);
        $this->assertFalse($service->ready($shop->fresh()));
        $this->assertNull($shop->fresh()->expires_at);
        Cache::put('bot:'.$shop->id.':state', ['status' => 'CONNECTED'], 90);
        $service->activate($shop->fresh());
        $service->activate($shop->fresh());
        $this->assertSame('full', $shop->fresh()->category);
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
        $this->assertSame('applied', $invoice->fresh()->status);
        $this->assertTrue(now()->addDays(30)->diffInSeconds($shop->fresh()->expires_at, true) < 2);
    }

    public function test_data_migration_preserves_money_dates_and_snapshots_and_remaps_only_matching_voucher_durations(): void
    {
        $owner = $this->owner();
        $old = $this->offer('bot');
        $full = $this->offer();
        $unmatched = $this->offer('web', 7);
        $snapshot = $old->snapshot();
        $shop = Store::create(['owner_id' => $owner->id, 'name' => 'Existing', 'slug' => 'existing-unified', 'category' => 'bot', 'status' => 'active', 'starts_at' => now()->subDays(4), 'expires_at' => now()->addDays(26), 'subscription' => $snapshot, 'branding' => ['name' => 'Keep']]);
        $expiry = $shop->expires_at;
        $invoice = SubscriptionInvoice::create(['owner_id' => $owner->id, 'store_id' => $shop->id, 'purpose' => 'initial', 'snapshot' => $snapshot, 'status' => 'applied', 'total' => 90123]);
        $voucher = DiscountVoucher::factory()->create(['scope_key' => 'platform', 'all_targets' => false, 'targets' => ['services' => [], 'categories' => [], 'products' => [], 'plans' => [(string) $old->id]]]);
        $orphan = DiscountVoucher::factory()->create(['scope_key' => 'platform', 'all_targets' => false, 'targets' => ['services' => [], 'categories' => [], 'products' => [], 'plans' => [(string) $unmatched->id]]]);
        $migration = require database_path('migrations/2026_11_02_141827_unify_web_and_whatsapp_subscription_package.php');
        $migration->up();
        $migration->up();
        $this->assertSame('full', $shop->fresh()->category);
        $this->assertTrue($expiry->equalTo($shop->fresh()->expires_at));
        $this->assertSame($snapshot['price'], $shop->fresh()->subscription['price']);
        $this->assertSame('bot', $shop->fresh()->subscription['legacy_package']['category']);
        $this->assertSame(['name' => 'Keep'], $shop->fresh()->branding);
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
        $this->assertSame(90123, $invoice->fresh()->total);
        $this->assertSame([(string) $full->id], $voucher->fresh()->targets['plans']);
        $this->assertFalse($orphan->fresh()->active);
        $this->assertFalse($old->fresh()->active);
        $this->assertTrue($full->fresh()->active);
    }

    public function test_historical_scheduled_change_keeps_its_duration_but_never_restricts_channels_again(): void
    {
        $owner = $this->owner();
        $shop = Store::create(['owner_id' => $owner->id, 'name' => 'Schedule', 'slug' => 'schedule-bundle', 'status' => 'expired', 'starts_at' => now()->subDays(31), 'expires_at' => now()->subSecond()]);
        $snapshot = $this->offer('web')->snapshot();
        $invoice = SubscriptionInvoice::create(['owner_id' => $owner->id, 'store_id' => $shop->id, 'purpose' => 'downgrade', 'snapshot' => $snapshot, 'status' => 'paid', 'paid_at' => now()->subDay()]);
        app(SubscriptionService::class)->tick($shop);
        $this->assertSame('full', $shop->fresh()->category);
        $this->assertSame('active', $shop->fresh()->status);
        $this->assertSame('applied', $invoice->fresh()->status);
        $this->assertSame($snapshot, $invoice->fresh()->snapshot);
    }
}
