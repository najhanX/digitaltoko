<?php

namespace Tests\Feature;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class OptionalOwnerIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.owner_ktp_required' => false, 'platform.domain' => 'localhost', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    private function owner(string $status = 'unsubmitted'): User
    {
        $owner = User::factory()->create();
        $owner->forceFill(['role' => 'owner', 'verification_status' => $status, 'hanshop_user_id' => 'HD'.$owner->id, 'phone' => '62812345000'.$owner->id, 'phone_verified_at' => now(), 'email_verified_at' => now()])->save();

        return $owner;
    }

    public function test_unsubmitted_pending_and_rejected_owners_can_create_without_faking_approval(): void
    {
        foreach (['unsubmitted', 'pending', 'rejected'] as $index => $status) {
            $owner = $this->owner($status);
            $this->actingAs($owner)->get('/owner/profile')->assertOk()->assertInertia(fn (Page $page) => $page->where('identityRequired', false)->where('workspace.canCreate', true));
            $this->post('/owner/stores', ['name' => 'Toko Tes', 'slug' => 'tanpa-ktp-'.$index, 'template' => 'aurora'])->assertRedirect();
            $store = Store::where('owner_id', $owner->id)->firstOrFail();
            $this->get('/stores/'.$store->id.'/subscription')->assertOk();
            $this->assertSame($status, $owner->fresh()->verification_status);
            $this->assertNull($owner->fresh()->ktp_path);
        }
    }

    public function test_contact_verification_and_store_ownership_remain_required(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($owner)->post('/owner/stores', ['name' => 'Toko', 'slug' => 'email-belum', 'template' => 'aurora'])->assertRedirect();
        $this->assertDatabaseCount('stores', 0);
        $owner->forceFill(['email_verified_at' => now(), 'phone_verified_at' => null])->save();
        $this->post('/owner/stores', ['name' => 'Toko', 'slug' => 'wa-belum', 'template' => 'aurora'])->assertForbidden();
        $other = $this->owner();
        $store = Store::create(['owner_id' => $other->id, 'name' => 'Toko Lain', 'slug' => 'toko-lain', 'status' => 'draft', 'template' => 'aurora']);
        $this->get('/owner/stores/'.$store->id.'/manage')->assertNotFound();
    }

    public function test_disabling_identity_stops_new_submissions_but_preserves_previous_status(): void
    {
        Storage::fake('local');
        $owner = $this->owner('pending');
        $this->actingAs($owner)->post('/owner/profile/verification', [])->assertNotFound();
        $this->assertSame('pending', $owner->fresh()->verification_status);
        config(['platform.owner_ktp_required' => true]);
        $this->post('/owner/stores', ['name' => 'Toko', 'slug' => 'tetap-terkunci', 'template' => 'aurora'])->assertForbidden();
    }

    public function test_subscription_checkout_no_longer_requires_ktp_approval(): void
    {
        config(['services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('x', 40)]);
        Http::fake(['https://hanshop.test/*' => fn ($request) => Http::response(['status' => 'SUCCESS', 'data' => ['invoice' => ['invoice_id' => $request['invoice_id'], 'id_user' => $request['id_user'], 'amount' => $request['amount'], 'total' => $request['amount'] + 123, 'id_payment' => 'DT'.$request['invoice_id'], 'status' => 'PENDING', 'qr_url' => 'https://example.test/qr.png', 'expires_at' => now()->addMinutes(15)->toIso8601String(), 'paid_at' => null]]])]);
        $owner = $this->owner();
        $store = Store::create(['owner_id' => $owner->id, 'name' => 'Toko', 'slug' => 'sewa-tanpa-ktp', 'status' => 'draft', 'template' => 'aurora']);
        $option = PlanOption::create(['name' => 'Paket Uji', 'category' => 'full', 'plan' => 'galaxy', 'price' => 50000, 'duration_days' => 30, 'active' => true]);
        $invoice = app(SubscriptionService::class)->checkout($store, $option);
        $this->assertSame(50123, $invoice->total);
        $this->assertSame('unsubmitted', $owner->fresh()->verification_status);
    }
}
