<?php

namespace Tests\Feature;

use App\Mail\CustomerOtpMail;
use App\Mail\StoreMessageMail;
use App\Models\Customer;
use App\Models\CustomerNotice;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\BotManager;
use App\Services\CustomerCommerce;
use App\Services\CustomerDelivery;
use App\Services\StoreMail;
use App\Support\HanshopIdentity;
use App\Support\OwnerOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

class StoreNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->freezeTime();
        Queue::fake();
        Http::preventStrayRequests();
        config(['platform.domain' => 'localhost', 'inertia.ssr.enabled' => false, 'cache.default' => 'array', 'services.bot_manager.url' => 'http://127.0.0.1:3099', 'services.bot_manager.token' => str_repeat('m', 64)]);
    }

    private function shop(string $category = 'web'): Store
    {
        return Store::create(['owner_id' => User::factory()->create(['role' => 'owner', 'verification_status' => 'approved'])->id,
            'name' => 'Toko Uji', 'slug' => Str::lower(Str::random(10)), 'category' => $category, 'plan' => 'galaxy', 'status' => 'active', 'template' => 'nebula', 'expires_at' => now()->addMonth()]);
    }

    private function base(Store $store): string
    {
        return 'http://'.$store->slug.'.localhost';
    }

    public function test_all_subscriptions_connect_and_receive_notices_and_access_chat_commerce_and_catalog(): void
    {
        $store = $this->shop();
        Http::fake(['127.0.0.1:3099/*' => Http::response(['status' => 'SUCCESS'])]);
        $url = 'http://localhost/stores/'.$store->id.'/bot';
        $this->actingAs($store->owner)->get($url)->assertOk();
        $this->post($url.'/connect', ['phone' => '081234567890', 'method' => 'qr'])->assertSessionHasNoErrors();
        $store->refresh();
        $store->update(['worker_token_hash' => hash('sha256', str_repeat('w', 64))]);
        $this->withToken(str_repeat('w', 64))->getJson('http://localhost/api/internal/bot/access')->assertJsonPath('data.allowed', true)->assertJsonPath('data.can_connect', true);
        $this->getJson('http://localhost/api/internal/bot/catalog?view=services')->assertOk();
        $this->postJson('http://localhost/api/internal/bot/commerce', ['phone' => '6281234567890', 'action' => 'balance'])->assertOk();
        $this->getJson('http://localhost/api/internal/bot/notices')->assertOk();
    }

    public function test_customer_whatsapp_otp_uses_only_the_store_worker_and_brand(): void
    {
        $store = $this->shop();
        Cache::put('bot:'.$store->id.':state', ['status' => 'CONNECTED'], 90);
        Http::fake(['127.0.0.1:3099/*' => Http::response(['status' => 'SUCCESS'])]);
        $this->mock(HanshopIdentity::class)->shouldNotReceive('call');
        Customer::create(['store_id' => $store->id, 'name' => 'Buyer', 'phone' => '6281234567890', 'phone_verified_at' => now()]);
        $this->post($this->base($store).'/account/otp', ['phone' => '081234567890'])->assertSessionHasNoErrors();
        Http::assertSent(fn ($r) => $r->url() === 'http://127.0.0.1:3099/stores/'.$store->id.'/message' && str_contains($r['message'], 'Kode OTP '.$store->name) && ! str_contains($r['message'], 'DigitalToko'));
    }

    public function test_email_registration_without_whatsapp_verifies_only_email_and_cannot_claim_existing_phone_account(): void
    {
        $store = $this->shop();
        $code = null;
        $this->mock(StoreMail::class, function ($mock) use (&$code, $store) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('send')->once()->withArgs(function ($shop, $to, $mail) use (&$code, $store) {
                $code = $mail->code;

                return $shop->id === $store->id && $to === 'buyer@example.test' && $mail instanceof CustomerOtpMail && $mail->storeName === $store->name;
            });
        });
        $this->mock(HanshopIdentity::class)->shouldNotReceive('call');
        $input = ['channel' => 'email', 'intent' => 'register', 'phone' => '081234567890', 'email' => 'buyer@example.test', 'name' => 'Buyer', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'];
        $this->post($this->base($store).'/account/otp', $input)->assertSessionHasNoErrors();
        $this->get($this->base($store).'/account/register')->assertInertia(fn (Page $p) => $p->where('challengeChannel', 'email')->where('otpReady', false));
        $this->post($this->base($store).'/account/verify', ['otp' => $code])->assertRedirect('/account')->assertSessionHasNoErrors();
        $customer = Customer::where('store_id', $store->id)->sole();
        $this->assertNull($customer->phone_verified_at);
        $this->assertNotNull($customer->email_verified_at);
        $this->get($this->base($store).'/account')->assertOk();
        $commerce = app(CustomerCommerce::class);
        $commerce->settings($store)->update(['gateway' => 'midtrans', 'credentials' => ['server_key' => 'fixture-key']]);
        $order = $commerce->begin($store, ['purpose' => 'topup', 'amount' => 5000, 'payment' => 'gateway', 'idempotency_key' => (string) Str::uuid()], $customer);
        $this->assertSame($customer->id, $order->customer_id);
        Auth::guard('customer')->logout();
        $this->post($this->base($store).'/account/otp', ['phone' => '081234567890'])->assertSessionHasErrors('phone');
        $this->post($this->base($store).'/account/otp', [...$input, 'email' => 'attacker@example.test'])->assertSessionHasErrors('phone');
        $this->post($this->base($store).'/account/password', ['email' => 'buyer@example.test', 'password' => 'new-password-123'])->assertRedirect('/account');
        Http::assertNothingSent();
    }

    public function test_unavailable_whatsapp_never_uses_platform_sender(): void
    {
        $store = $this->shop();
        $this->mock(HanshopIdentity::class)->shouldNotReceive('call');
        Customer::create(['store_id' => $store->id, 'name' => 'Buyer', 'phone' => '6281234567890', 'phone_verified_at' => now()]);
        $this->post($this->base($store).'/account/otp', ['phone' => '081234567890'])->assertSessionHasErrors('phone');
        $this->get($this->base($store).'/account/login')->assertInertia(fn (Page $p) => $p->where('challengePending', false));
        Http::assertNothingSent();
    }

    public function test_smtp_password_is_encrypted_hidden_preserved_and_isolated_from_other_stores(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $this->partialMock(StoreMail::class)->shouldReceive('validateHost')->with('smtp.example.com')->andReturn('93.184.216.34');
        $url = '/stores/'.$store->id.'/notifications';
        $input = ['mode' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'shop@example.com', 'password' => 'private-smtp-password', 'from_address' => 'shop@example.com'];
        $this->actingAs($store->owner)->patch($url, $input)->assertSessionHasNoErrors();
        $store->refresh();
        $this->assertStringNotContainsString('private-smtp-password', $store->getRawOriginal('notification_settings'));
        $this->assertArrayNotHasKey('notification_settings', $store->toArray());
        $this->get($url)->assertInertia(fn (Page $p) => $p->where('emailSettings.has_password', true)->missing('emailSettings.password')->missing('store.notification_settings'));
        $this->patch($url, [...$input, 'password' => '', 'username' => 'updated@example.com'])->assertSessionHasNoErrors();
        $this->assertSame('private-smtp-password', $store->fresh()->notification_settings['password']);
        $this->assertNull($other->fresh()->notification_settings);
        $this->actingAs($other->owner)->get($url)->assertForbidden();
        $this->patch($url, $input)->assertForbidden();
    }

    public function test_smtp_rejects_internal_destinations_and_insecure_ports(): void
    {
        $store = $this->shop();
        $input = ['mode' => 'smtp', 'host' => '127.0.0.1', 'port' => 587, 'username' => 'a', 'password' => 'secret', 'from_address' => 'a@example.com'];
        $url = '/stores/'.$store->id.'/notifications';
        $this->actingAs($store->owner)->patch($url, $input)->assertSessionHasErrors('host');
        $this->patch($url, [...$input, 'host' => 'localhost'])->assertSessionHasErrors('host');
        $this->patch($url, [...$input, 'port' => 25])->assertSessionHasErrors('port');
        $this->assertNull($store->fresh()->notification_settings);
    }

    public function test_staff_needs_explicit_notification_permission_and_cannot_modify_with_read_only_access(): void
    {
        $store = $this->shop();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Reader', 'username' => 'reader', 'email' => 'reader@example.test', 'password' => 'test-password', 'active' => true, 'permissions' => ['notifications.view']]);
        $url = '/stores/'.$store->id.'/notifications';
        $this->actingAs($staff, 'staff')->get($url)->assertInertia(fn (Page $p) => $p->where('canManage', false));
        $this->patch($url, ['mode' => 'platform'])->assertForbidden();
        $this->post($url.'/test', ['email' => 'test@example.com'])->assertForbidden();
        $other = $this->shop();
        $this->get('/stores/'.$other->id.'/notifications')->assertForbidden();
    }

    public function test_smtp_transport_is_created_per_send_without_changing_global_configuration(): void
    {
        $store = $this->shop();
        $other = $this->shop();
        $settings = ['mode' => 'smtp', 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'one', 'password' => 'one-secret', 'from_address' => 'one@example.com'];
        $store->update(['notification_settings' => $settings]);
        $other->update(['notification_settings' => [...$settings, 'username' => 'two', 'password' => 'two-secret', 'from_address' => 'two@example.com']]);
        $sender = $this->partialMock(StoreMail::class);
        $sender->shouldReceive('validateHost')->twice()->andReturn('93.184.216.34');
        foreach (['one', 'two'] as $username) {
            $mailer = \Mockery::mock(Mailer::class);
            $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn(new EsmtpTransport('93.184.216.34', 587, false));
            $mailer->shouldReceive('to')->once()->with('buyer@example.com')->andReturnSelf();
            $mailer->shouldReceive('send')->once()->with(\Mockery::on(fn ($mail) => $mail->from[0]['address'] === $username.'@example.com'));
            Mail::shouldReceive('build')->once()->with(\Mockery::on(fn ($config) => $config['username'] === $username && $config['password'] === $username.'-secret' && $config['require_tls'] === true))->andReturn($mailer);
        }
        $default = config('mail.default');
        $sender->send($store, 'buyer@example.com', new CustomerOtpMail($store->name, '123456'));
        $sender->send($other, 'buyer@example.com', new CustomerOtpMail($other->name, '654321'));
        $this->assertSame($default, config('mail.default'));
    }

    public function test_order_email_uses_store_sender_and_does_not_mark_whatsapp_delivered(): void
    {
        $store = $this->shop();
        $notice = CustomerNotice::create(['store_id' => $store->id, 'event' => 'test:'.Str::uuid(), 'phone' => '6281234567890', 'email' => 'buyer@example.test', 'message' => 'Order information']);
        $this->mock(HanshopIdentity::class)->shouldNotReceive('call');
        $this->mock(StoreMail::class, function ($mock) use ($store) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('send')->once()->withArgs(fn ($shop, $email, $message) => $shop->id === $store->id && $email === 'buyer@example.test' && $message instanceof StoreMessageMail);
        });
        app(CustomerDelivery::class)->send($notice);
        app(CustomerDelivery::class)->send($notice);
        $this->assertNotNull($notice->fresh()->email_sent_at);
        $this->assertNull($notice->fresh()->wa_sent_at);
        Http::assertNothingSent();
    }

    public function test_email_otp_cannot_claim_an_unverified_address_on_an_existing_whatsapp_account(): void
    {
        $store = $this->shop();
        Customer::create(['store_id' => $store->id, 'name' => 'Original', 'phone' => '6281234567890', 'phone_verified_at' => now(), 'email' => 'unverified@example.test', 'balance' => 50000]);
        $this->mock(StoreMail::class)->shouldNotReceive('send');
        $this->post($this->base($store).'/account/otp', ['channel' => 'email', 'email' => 'unverified@example.test'])->assertSessionHasErrors('email');
        $this->post($this->base($store).'/account/otp', ['channel' => 'email', 'intent' => 'register', 'email' => 'unverified@example.test', 'phone' => '081234567890', 'name' => 'Attacker', 'password' => 'attacker-password-123', 'password_confirmation' => 'attacker-password-123'])->assertSessionHasErrors('phone');
        $this->assertGuest('customer');
        $this->assertSame(50000, Customer::sole()->balance);
    }

    public function test_authenticated_email_customer_must_prove_phone_before_bot_wallet_access(): void
    {
        $store = $this->shop('full');
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Buyer', 'phone' => '6281234567890', 'email' => 'buyer@example.test', 'email_verified_at' => now(), 'balance' => 50000]);
        Cache::put('bot:'.$store->id.':state', ['status' => 'CONNECTED'], 90);
        $code = null;
        Http::fake(function ($request) use (&$code) {
            preg_match('/\b([0-9]{6})\b/', $request['message'], $match);
            $code = $match[1];

            return Http::response(['status' => 'SUCCESS']);
        });
        $store->update(['worker_token_hash' => hash('sha256', str_repeat('w', 64))]);
        $this->withToken(str_repeat('w', 64))->postJson('http://localhost/api/internal/bot/commerce', ['phone' => $customer->phone, 'action' => 'balance'])->assertForbidden();
        $this->actingAs($customer, 'customer')->post($this->base($store).'/account/phone/otp')->assertRedirect('/account?tab=profile')->assertSessionHasNoErrors();
        $this->post($this->base($store).'/account/phone/verify', ['otp' => $code])->assertRedirect('/account?tab=profile')->assertSessionHasNoErrors();
        $this->assertNotNull($customer->fresh()->phone_verified_at);
        $this->withToken(str_repeat('w', 64))->postJson('http://localhost/api/internal/bot/commerce', ['phone' => $customer->phone, 'action' => 'balance'])->assertJsonPath('data.balance', 50000);
    }

    public function test_platform_otp_keeps_its_original_transport_while_store_platform_email_uses_store_brand(): void
    {
        $this->mock(HanshopIdentity::class)->shouldReceive('call')->once()->with('otp', \Mockery::on(fn ($data) => $data['phone'] === '6281234567890'))->andReturn([]);
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());
        app(OwnerOtp::class)->issue($request, 'login', 'wa', '6281234567890', []);
        config(['mail.default' => 'smtp', 'mail.from.address' => 'platform@example.com']);
        Mail::fake();
        $store = $this->shop();
        app(StoreMail::class)->send($store, 'buyer@example.com', new CustomerOtpMail($store->name, '123456'));
        Mail::assertSent(CustomerOtpMail::class, fn ($mail) => $mail->from[0]['address'] === 'platform@example.com' && $mail->from[0]['name'] === $store->name && $mail->storeName === $store->name);
    }

    public function test_otp_transport_failure_distinguishes_old_manager_offline_worker_and_unconfirmed_send(): void
    {
        $store = $this->shop();
        Http::fake(['127.0.0.1:3099/*' => Http::sequence()->push([], 404)->push([], 409)->push([], 503)]);
        foreach ([404 => 'versi lama', 409 => 'belum aktif', 503 => 'belum mengonfirmasi'] as $status => $text) {
            try {
                app(BotManager::class)->command($store, 'message', ['phone' => '6281234567890', 'message' => 'Test only']);
                $this->fail('Unsuccessful OTP cannot be acknowledged as sent.');
            } catch (ValidationException $error) {
                $this->assertStringContainsString($text, $error->errors()['connection'][0]);
            }
        }
    }
}
