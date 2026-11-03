<?php

namespace Tests\Feature;

use App\Mail\OwnerOtpMail;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\BotManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    private ?string $code = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'cache.default' => 'array', 'inertia.ssr.enabled' => false,
            'services.hanshop.url' => 'https://hanshop.test', 'services.hanshop.token' => str_repeat('t', 64)]);
        Cache::flush();
        Http::preventStrayRequests();
        Mail::fake();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/otp')) {
                $this->code = $request['otp'];

                return Http::response(['status' => 'SUCCESS', 'data' => ['sent' => true]]);
            }

            return Http::response(['status' => 'SUCCESS', 'data' => ['user' => [
                'id' => 'HD001', 'name' => $request['name'] ?? 'Owner', 'phone' => $request['phone'] ?? '6281234567890',
                'email' => $request['email'] ?? 'owner@example.test', 'email_verified_at' => now()->toIso8601String(),
            ]]]);
        });
    }

    private function owner(bool $verified = true): User
    {
        return User::factory()->create(['hanshop_user_id' => 'HD001', 'phone' => '6281234567890',
            'phone_verified_at' => now(), 'email_verified_at' => $verified ? now() : null]);
    }

    private function shop(): Store
    {
        $store = Store::create(['owner_id' => User::factory()->create()->id, 'name' => 'Profile shop', 'slug' => 'profile-shop',
            'category' => 'full', 'plan' => 'galaxy', 'template' => 'nebula', 'status' => 'active', 'expires_at' => now()->addMonth()]);
        Cache::put('bot:'.$store->id.':state', ['status' => 'CONNECTED'], 90);
        $this->mock(BotManager::class, function ($mock) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('command')->andReturnUsing(function ($store, $action, $data) {
                preg_match('/\b([0-9]{6})\b/', $data['message'], $match);
                $this->code = $match[1];

                return [];
            });
        });

        return $store;
    }

    private function buyer(Store $store, string $phone = '6281234567801'): Customer
    {
        return Customer::create(['store_id' => $store->id, 'name' => 'Buyer', 'phone' => $phone, 'phone_verified_at' => now(), 'balance' => 12000]);
    }

    public function test_owner_email_gate_blocks_mutations_until_email_otp_is_verified(): void
    {
        $owner = $this->owner(false);
        $this->actingAs($owner)->get('/owner/account')->assertInertia(fn (Page $page) => $page->component('Owner/Account')->where('ownerEmailGate.required', true));
        $this->patch('/owner/account', ['name' => 'Blocked'])->assertRedirect('/owner/account');
        $this->assertNotSame('Blocked', $owner->fresh()->name);
        $this->post('/owner/profile/email/otp', ['email' => 'NEW@example.test'])->assertSessionHasNoErrors();
        $code = Mail::sent(OwnerOtpMail::class)->first()->code;
        $this->post('/owner/profile/email/verify', ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->assertNull($owner->fresh()->email_verified_at);
        $this->post('/owner/profile/email/verify', ['otp' => $code])->assertSessionHasNoErrors();
        $this->assertSame('new@example.test', $owner->fresh()->email);
        $this->assertNotNull($owner->fresh()->email_verified_at);
        $this->get('/owner/account')->assertInertia(fn (Page $page) => $page->where('ownerEmailGate.required', false));
        $this->post('/owner/profile/email/verify', ['otp' => $code])->assertSessionHasErrors('otp');
    }

    public function test_placeholder_email_cannot_bypass_gate_with_a_verified_timestamp(): void
    {
        $owner = $this->owner();
        $owner->update(['email' => 'hd001@identity.invalid']);
        $this->actingAs($owner)->get('/owner/account')->assertInertia(fn (Page $page) => $page->where('ownerEmailGate.required', true));
        $this->patch('/owner/account', ['name' => 'Blocked'])->assertRedirect('/owner/account');
        $this->assertNotSame('Blocked', $owner->fresh()->name);
    }

    public function test_expired_owner_phone_otp_does_not_change_identity(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/owner/account/phone/otp', ['phone' => '081234567899'])->assertSessionHasNoErrors();
        $code = $this->code;
        $this->travel(6)->minutes();
        $this->post('/owner/account/phone/verify', ['otp' => $code])->assertSessionHasErrors('otp');
        $this->assertSame('6281234567890', $owner->fresh()->phone);
    }

    public function test_owner_profile_updates_sync_to_hanshop_without_changing_ktp(): void
    {
        $owner = $this->owner();
        $owner->forceFill(['legal_name' => 'Legal name', 'verification_status' => 'approved'])->save();
        $this->actingAs($owner)->patch('/owner/account', ['name' => 'New display'])->assertSessionHasNoErrors();
        $this->assertSame('New display', $owner->fresh()->name);
        $this->assertSame('Legal name', $owner->fresh()->legal_name);
        $this->assertSame('approved', $owner->fresh()->verification_status);
        $this->post('/owner/account/phone/otp', ['phone' => '081234567899'])->assertSessionHasNoErrors();
        $this->assertSame('6281234567890', $owner->fresh()->phone);
        $code = $this->code;
        $this->post('/owner/account/phone/verify', ['otp' => $code])->assertSessionHasNoErrors();
        $this->assertSame('6281234567899', $owner->fresh()->phone);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/profile/contact') && ($request['phone'] ?? null) === '6281234567899' && $request['previous_phone'] === '6281234567890');
        $this->post('/owner/account/phone/verify', ['otp' => $code])->assertSessionHasErrors('otp');
    }

    public function test_conflicts_sync_failure_and_wrong_roles_do_not_overwrite_owner_contacts(): void
    {
        $owner = $this->owner();
        User::factory()->create(['phone' => '6281234567899']);
        $this->actingAs($owner)->post('/owner/account/phone/otp', ['phone' => '081234567899'])->assertSessionHasErrors('phone');
        Http::swap(new Factory);
        Http::fake(['*' => Http::response(['status' => 'FAILED'], 400)]);
        $this->patch('/owner/account', ['name' => 'Not saved'])->assertSessionHasErrors();
        $this->assertNotSame('Not saved', $owner->fresh()->name);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->patch('/owner/account', ['name' => 'Denied'])->assertForbidden();
    }

    public function test_owner_challenge_cannot_be_used_by_another_owner(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/owner/account/phone/otp', ['phone' => '081234567899'])->assertSessionHasNoErrors();
        $this->actingAs(User::factory()->create())->post('/owner/account/phone/verify', ['otp' => $this->code])->assertForbidden();
        $this->assertSame('6281234567890', $owner->fresh()->phone);
    }

    public function test_customer_name_and_phone_change_preserve_balance_and_require_otp(): void
    {
        $store = $this->shop();
        $customer = $this->buyer($store);
        $base = 'http://profile-shop.localhost';
        $this->actingAs($customer, 'customer')->patch($base.'/account/profile', ['name' => 'New buyer', 'balance' => 999999])->assertSessionHasNoErrors();
        $this->assertSame('New buyer', $customer->fresh()->name);
        $this->assertSame(12000, $customer->fresh()->balance);
        $this->post($base.'/account/phone/otp', ['phone' => '081234567899'])->assertSessionHasNoErrors();
        $this->assertSame('6281234567801', $customer->fresh()->phone);
        $this->post($base.'/account/phone/verify', ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->post($base.'/account/phone/verify', ['otp' => $this->code])->assertSessionHasNoErrors();
        $this->assertSame('6281234567899', $customer->fresh()->phone);
        $this->assertNotNull($customer->fresh()->phone_verified_at);
    }

    public function test_customer_phone_conflict_rechecked_and_cross_store_access_denied(): void
    {
        $store = $this->shop();
        $customer = $this->buyer($store);
        $base = 'http://profile-shop.localhost';
        $this->actingAs($customer, 'customer')->post($base.'/account/phone/otp', ['phone' => '081234567899'])->assertSessionHasNoErrors();
        $this->buyer($store, '6281234567899');
        $this->post($base.'/account/phone/verify', ['otp' => $this->code])->assertSessionHasErrors('phone');
        $this->assertSame('6281234567801', $customer->fresh()->phone);
        $other = Store::create(['owner_id' => $store->owner_id, 'name' => 'Other', 'slug' => 'other-profile', 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'template' => 'aurora']);
        $this->patch('http://other-profile.localhost/account/profile', ['name' => 'Intruder'])->assertUnauthorized();
        $this->assertSame('Buyer', $customer->fresh()->name);
        Auth::guard('customer')->logout();
        $this->patch($base.'/account/profile', ['name' => 'Guest'])->assertUnauthorized();
    }
}
