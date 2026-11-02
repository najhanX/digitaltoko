<?php

namespace Tests\Feature;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'digitaltoko.example', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
    }

    public function test_public_prices_use_only_active_current_offers_and_follow_admin_changes(): void
    {
        $offer = PlanOption::create(['name' => 'Web 90 hari', 'category' => 'full', 'plan' => 'galaxy', 'duration_days' => 90, 'price' => 240000, 'active' => true]);
        PlanOption::create(['name' => 'Tidak aktif', 'category' => 'bot', 'plan' => 'galaxy', 'duration_days' => 30, 'price' => 1000, 'active' => false]);
        PlanOption::create(['name' => 'Paket lama', 'category' => 'bot', 'plan' => 'orbit', 'duration_days' => 30, 'price' => 1000, 'active' => true]);
        $this->get('https://digitaltoko.example/paket')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Pricing')->where('domain', 'digitaltoko.example')->has('offers', 1)
            ->where('offers.0.id', $offer->id)->where('offers.0.price', 240000)->where('offers.0.duration_days', 90)
            ->missing('offers.0.created_at')->missing('offers.0.active'));
        $offer->update(['price' => 222000]);
        $this->get('https://digitaltoko.example/paket')->assertInertia(fn (Assert $page) => $page->where('offers.0.price', 222000));
        $offer->update(['active' => false]);
        $this->get('https://digitaltoko.example/paket')->assertOk()->assertInertia(fn (Assert $page) => $page->has('offers', 0));
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('subscription_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_availability_normalizes_names_and_does_not_reserve_or_create_a_store(): void
    {
        $this->getJson('https://digitaltoko.example/paket/subdomain?slug=%20Toko-Baru%20')->assertOk()
            ->assertExactJson(['slug' => 'toko-baru', 'available' => true]);
        $this->getJson('https://digitaltoko.example/paket/subdomain?slug=toko-baru')->assertOk();
        $this->assertDatabaseCount('stores', 0);
        $this->assertDatabaseCount('subscription_invoices', 0);
        Http::assertNothingSent();
    }

    public function test_taken_reserved_and_invalid_subdomains_are_rejected_without_exposing_owner_data(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Store::create(['owner_id' => $owner->id, 'name' => 'Nama privat', 'slug' => 'sudah-ada', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'draft']);
        foreach (['sudah-ada', 'SUDAH-ADA', 'admin', 'a', '-toko', 'toko-', 'dua--kata', 'toko.test', 'dua kata', str_repeat('a', 41)] as $slug) {
            $response = $this->getJson('https://digitaltoko.example/paket/subdomain?'.http_build_query(['slug' => $slug]))
                ->assertUnprocessable()->assertJsonValidationErrors('slug');
            $this->assertStringNotContainsString('Nama privat', $response->getContent());
            $this->assertStringNotContainsString($owner->email, $response->getContent());
        }
        $this->getJson('https://digitaltoko.example/paket/subdomain?slug[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->getJson('https://digitaltoko.example/paket/subdomain')->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_availability_is_rechecked_when_owner_submits_creation(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved', 'phone_verified_at' => now(), 'email_verified_at' => now()]);
        $this->getJson('https://digitaltoko.example/paket/subdomain?slug=rebutan')->assertOk();
        Store::create(['owner_id' => $owner->id, 'name' => 'Lebih dulu', 'slug' => 'rebutan', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'draft']);
        $this->actingAs($owner)->postJson('https://digitaltoko.example/owner/stores', ['name' => 'Toko saya', 'slug' => 'REBUTAN', 'category' => 'full', 'template' => 'aurora'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->assertDatabaseCount('stores', 1);
    }

    public function test_pricing_and_availability_are_not_exposed_on_a_tenant_host(): void
    {
        $this->get('https://unknown.digitaltoko.example/paket')->assertNotFound();
        $this->getJson('https://unknown.digitaltoko.example/paket/subdomain?slug=toko-baru')->assertNotFound();
    }
}
