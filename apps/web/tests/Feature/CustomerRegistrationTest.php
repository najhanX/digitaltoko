<?php

namespace Tests\Feature;

use App\Mail\OwnerOtpMail;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Services\CustomerCommerce;
use App\Support\HanshopIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Page;
use Tests\TestCase;

class CustomerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private ?string $otpCode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'cache.default' => 'array', 'inertia.ssr.enabled' => false]);
        Http::preventStrayRequests();
        $this->mock(HanshopIdentity::class, function ($mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('call')->with('otp', \Mockery::on(function ($data) {
                $this->otpCode = $data['otp'];

                return preg_match('/^62[1-9][0-9]{7,12}$/', $data['phone']) === 1;
            }))->andReturn(['sent' => true]);
        });
    }

    private function shop(): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner'])->id, 'name' => 'Register shop', 'slug' => Str::lower(Str::random(10)), 'category' => 'full', 'plan' => 'galaxy', 'template' => 'nebula', 'status' => 'active', 'expires_at' => now()->addMonth()]);
    }

    private function url(Store $store, string $path): string
    {
        return 'http://'.$store->slug.'.localhost/account/'.$path;
    }

    private function requestOtp(Store $store, array $extra = [])
    {
        return $this->withSession(['_previous' => ['url' => 'http://'.$store->slug.'.localhost/orders/old-invoice']])
            ->post($this->url($store, 'otp'), [...['intent' => 'register', 'name' => 'Customer test', 'email' => 'buyer@example.test', 'password' => 'registration-password-123', 'password_confirmation' => 'registration-password-123', 'phone' => '081234567890'], ...$extra]);
    }

    public function test_registration_page_and_verified_account_creation_are_explicit_and_store_scoped(): void
    {
        $shop = $this->shop();
        $this->get($this->url($shop, 'register'))->assertInertia(fn (Page $page) => $page->where('initialMode', 'register')->where('challengePending', false)->where('canRegister', true));
        $this->requestOtp($shop)->assertRedirect('/account/register')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('customers', 0);
        $this->get($this->url($shop, 'register'))->assertInertia(fn (Page $page) => $page->where('challengePending', true)->where('challengePhone', '6281••••7890')->where('challengeExpiresAt', now()->addMinutes(5)->timestamp));
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode, 'intent' => 'register'])->assertRedirect('/account')->assertSessionHasNoErrors();
        $customer = Customer::where('store_id', $shop->id)->sole();
        $this->assertSame('Customer test', $customer->name);
        $this->assertSame('6281234567890', $customer->phone);
        $this->assertNotNull($customer->phone_verified_at);
        $this->assertSame('buyer@example.test', $customer->email);
        $this->assertNull($customer->email_verified_at);
        $this->assertTrue(Hash::check('registration-password-123', $customer->password));
        $this->assertNotSame('registration-password-123', $customer->getRawOriginal('password'));
        $this->assertSame(0, $customer->balance);
        $this->assertSame(0, $customer->sandbox_balance);
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertDatabaseCount('users', 1);
        $this->get($this->url($shop, 'register'))->assertRedirect('/account');
        Http::assertNothingSent();
    }

    public function test_registration_requires_name_and_valid_phone_and_wrong_otp_cannot_create_account(): void
    {
        $shop = $this->shop();
        $this->requestOtp($shop, ['email' => ''])->assertSessionHasErrors('email');
        $this->requestOtp($shop, ['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $this->requestOtp($shop, ['name' => ''])->assertRedirect('/account/register')->assertSessionHasErrors('name');
        $this->requestOtp($shop, ['phone' => 'wrong'])->assertRedirect('/account/register')->assertSessionHasErrors();
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        $this->post($this->url($shop, 'verify'), ['otp' => '000000', 'intent' => 'register'])->assertRedirect('/account/register')->assertSessionHasErrors('otp');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_challenge_cannot_be_reused_or_verified_at_another_store(): void
    {
        $shop = $this->shop();
        $other = $this->shop();
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        $code = $this->otpCode;
        $this->post($this->url($other, 'verify'), ['otp' => $code, 'intent' => 'register'])->assertSessionHasErrors('otp');
        $this->post($this->url($shop, 'verify'), ['otp' => $code, 'intent' => 'register'])->assertRedirect('/account');
        $this->post($this->url($shop, 'verify'), ['otp' => $code, 'intent' => 'register'])->assertSessionHasErrors('otp');
        $this->assertSame(0, Customer::where('store_id', $other->id)->count());
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_expired_or_exhausted_challenge_is_not_displayed_as_active(): void
    {
        $shop = $this->shop();
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->url($shop, 'verify'), ['otp' => '000000', 'intent' => 'register'])->assertSessionHasErrors('otp');
        }
        $this->get($this->url($shop, 'register'))->assertInertia(fn (Page $page) => $page->where('challengePending', false));
        $this->travel(61)->seconds();
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        $this->travel(6)->minutes();
        $this->get($this->url($shop, 'register'))->assertInertia(fn (Page $page) => $page->where('challengePending', false));
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode, 'intent' => 'register'])->assertSessionHasErrors('otp');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_same_phone_has_independent_accounts_per_store_and_existing_account_is_not_overwritten(): void
    {
        $shop = $this->shop();
        $other = $this->shop();
        $existing = Customer::create(['store_id' => $shop->id, 'phone' => '6281234567890', 'name' => 'Existing customer', 'phone_verified_at' => now(), 'balance' => 50000]);
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode, 'intent' => 'register'])->assertRedirect('/account');
        $this->assertSame('Existing customer', $existing->fresh()->name);
        $this->assertSame(50000, $existing->fresh()->balance);
        Auth::guard('customer')->logout();
        $this->travel(61)->seconds();
        $this->requestOtp($other)->assertSessionHasNoErrors();
        $this->post($this->url($other, 'verify'), ['otp' => $this->otpCode, 'intent' => 'register'])->assertRedirect('/account');
        $this->assertSame(0, Customer::where('store_id', $other->id)->sole()->balance);
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_closed_store_disables_registration_but_existing_customer_can_still_log_in(): void
    {
        $shop = $this->shop();
        app(CustomerCommerce::class)->settings($shop)->update(['closing' => true]);
        $customer = Customer::create(['store_id' => $shop->id, 'phone' => '6281234567890', 'name' => 'Existing customer', 'phone_verified_at' => now()]);
        $this->get($this->url($shop, 'register'))->assertInertia(fn (Page $page) => $page->where('canRegister', false));
        $this->requestOtp($shop)->assertSessionHasErrors('commerce');
        $this->requestOtp($shop, ['intent' => 'login'])->assertRedirect('/account/login')->assertSessionHasNoErrors();
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode])->assertRedirect('/account');
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_closure_between_otp_and_verification_blocks_creation_and_returns_to_registration(): void
    {
        $shop = $this->shop();
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        app(CustomerCommerce::class)->settings($shop)->update(['closing' => true]);
        $this->withSession(['_previous' => ['url' => '/orders/old-invoice']])->post($this->url($shop, 'verify'), ['otp' => $this->otpCode, 'intent' => 'register'])
            ->assertRedirect('/account/register')->assertSessionHasErrors('commerce');
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_invalid_email_password_returns_to_login_instead_of_old_invoice(): void
    {
        $shop = $this->shop();
        $this->withSession(['_previous' => ['url' => '/orders/old-invoice']])->post($this->url($shop, 'password'), ['email' => 'unknown@example.test', 'password' => 'not-the-password'])
            ->assertRedirect('/account/login')->assertSessionHasErrors('commerce');
        $this->assertGuest('customer');
    }

    public function test_email_is_normalized_bound_to_otp_and_unique_only_inside_each_store(): void
    {
        $shop = $this->shop();
        $other = $this->shop();
        Customer::create(['store_id' => $other->id, 'phone' => '6289999999999', 'name' => 'Other store', 'email' => 'buyer@example.test']);
        $this->requestOtp($shop, ['email' => ' Buyer@Example.Test '])->assertSessionHasNoErrors();
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode, 'intent' => 'register', 'email' => 'tampered@example.test'])->assertRedirect('/account');
        $this->assertSame('buyer@example.test', Customer::where('store_id', $shop->id)->sole()->email);
        Auth::guard('customer')->logout();
        $this->requestOtp($shop, ['phone' => '089999999999'])->assertSessionHasErrors('email');
        $this->assertSame(1, Customer::where('store_id', $shop->id)->count());
    }

    public function test_email_taken_while_otp_is_pending_is_rejected_without_creating_account(): void
    {
        $shop = $this->shop();
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        Customer::create(['store_id' => $shop->id, 'phone' => '6289999999999', 'name' => 'Other customer', 'email' => 'buyer@example.test']);
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode, 'intent' => 'register'])->assertRedirect('/account/register')->assertSessionHasErrors('email');
        $this->assertFalse(Customer::where('store_id', $shop->id)->where('phone', '6281234567890')->exists());
        $this->assertGuest('customer');
    }

    public function test_unknown_phone_cannot_bypass_registration_email_via_login(): void
    {
        $shop = $this->shop();
        $this->post($this->url($shop, 'otp'), ['phone' => '081234567890', 'intent' => 'login'])->assertRedirect('/account/login')->assertSessionHasErrors('phone');
        $this->assertNull($this->otpCode);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_registration_password_is_required_confirmed_and_never_cached_as_plaintext(): void
    {
        $shop = $this->shop();
        $this->requestOtp($shop, ['password' => ''])->assertSessionHasErrors('password');
        $this->requestOtp($shop, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->requestOtp($shop, ['password_confirmation' => 'different-password'])->assertSessionHasErrors('password');
        $this->assertNull($this->otpCode);
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        $challenge = Cache::get('owner-challenge:'.session('owner_challenge_customer-'.$shop->id.'-login'));
        $this->assertArrayNotHasKey('password', $challenge['payload']);
        $this->assertArrayNotHasKey('password_confirmation', $challenge['payload']);
        $this->assertTrue(Hash::check('registration-password-123', $challenge['payload']['password_hash']));
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode, 'password' => 'tampered-password-123'])->assertRedirect('/account');
        $this->assertTrue(Hash::check('registration-password-123', Customer::where('store_id', $shop->id)->sole()->password));
    }

    public function test_email_verification_preserves_registration_password_and_enables_email_login(): void
    {
        $shop = $this->shop();
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode])->assertRedirect('/account');
        $customer = Customer::where('store_id', $shop->id)->sole();
        $hash = $customer->password;
        Auth::guard('customer')->logout();
        $this->post($this->url($shop, 'password'), ['email' => $customer->email, 'password' => 'registration-password-123'])->assertSessionHasErrors('commerce');
        $this->actingAs($customer, 'customer');
        Mail::fake();
        $this->post($this->url($shop, 'email/otp'), ['email' => $customer->email])->assertSessionHasNoErrors();
        $code = Mail::sent(OwnerOtpMail::class)->first()->code;
        $this->post($this->url($shop, 'email/verify'), ['otp' => $code])->assertSessionHasNoErrors();
        $this->assertSame($hash, $customer->fresh()->password);
        $this->assertNotNull($customer->fresh()->email_verified_at);
        Auth::guard('customer')->logout();
        $this->post($this->url($shop, 'password'), ['email' => $customer->email, 'password' => 'registration-password-123'])->assertRedirect('/account');
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_registration_does_not_overwrite_existing_customer_password(): void
    {
        $shop = $this->shop();
        $customer = Customer::create(['store_id' => $shop->id, 'phone' => '6281234567890', 'name' => 'Existing', 'email' => 'buyer@example.test', 'password' => 'existing-password-123']);
        $this->requestOtp($shop)->assertSessionHasNoErrors();
        $this->post($this->url($shop, 'verify'), ['otp' => $this->otpCode])->assertRedirect('/account');
        $this->assertTrue(Hash::check('existing-password-123', $customer->fresh()->password));
    }
}
