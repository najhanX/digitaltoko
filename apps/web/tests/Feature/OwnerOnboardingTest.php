<?php

namespace Tests\Feature;

use App\Mail\OwnerOtpMail;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private ?array $identity = ['id' => 'HD001', 'name' => 'Owner Test', 'phone' => '6281234567890', 'email' => null, 'email_verified_at' => null];

    private ?string $deliveredOtp = null;

    private bool $registered = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'services.hanshop.url' => 'https://hanshop.test',
            'services.hanshop.token' => str_repeat('test-token-', 6), 'cache.default' => 'array']);
        Cache::flush();
        Mail::fake();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeBridge();
    }

    private function fakeBridge(): void
    {
        Http::fake(function (ClientRequest $request) {
            $data = ['user' => $this->identity];
            if (str_ends_with($request->url(), '/otp')) {
                $this->deliveredOtp = $request['otp'];
                $data = ['sent' => true];
            }
            if (str_ends_with($request->url(), '/register')) {
                $this->registered = true;
                $this->identity ??= ['id' => 'HD001', 'email' => null, 'email_verified_at' => null];
                $this->identity['phone'] = $request['phone'];
                $this->identity['name'] = $request['name'];
                $data = ['user' => $this->identity];
            }
            if (str_ends_with($request->url(), '/profile')) {
                $this->identity['email'] = $request['email'];
                $this->identity['email_verified_at'] = now()->toIso8601String();
                $data = ['user' => $this->identity];
            }

            return Http::response(['status' => 'SUCCESS', 'message' => 'OK', 'data' => $data]);
        });
    }

    private function owner(string $status = 'approved'): User
    {
        $owner = User::factory()->create();
        $owner->forceFill(['hanshop_user_id' => 'HD'.($owner->id + 100), 'phone' => '62812345000'.$owner->id,
            'phone_verified_at' => now(), 'email_verified_at' => now(), 'verification_status' => $status])->save();

        return $owner;
    }

    private function newStore(User $owner, string $slug = 'toko-test'): Store
    {
        return Store::create(['owner_id' => $owner->id, 'name' => 'Toko Test', 'slug' => $slug, 'category' => 'full', 'plan' => 'galaxy', 'status' => 'draft', 'template' => 'aurora']);
    }

    private function storeInput(string $slug = 'toko-baru'): array
    {
        return ['name' => 'Toko Baru', 'slug' => $slug, 'category' => 'full', 'plan' => 'galaxy', 'template' => 'atlas'];
    }

    private function staffInput(string $username = 'staff-one'): array
    {
        return ['name' => 'Staff One', 'username' => $username, 'email' => $username.'@example.test', 'password' => 'Strong-Test-Password-123', 'active' => true, 'permissions' => ['dashboard.view', 'bot.manage']];
    }

    private function submissionReference(User $owner): string
    {
        $response = $this->get('/admin/verifications')->assertOk();

        return collect($response->viewData('page')['props']['owners']['data'])->firstWhere('id', $owner->id)['submission_reference'];
    }

    public function test_existing_owner_logs_in_with_whatsapp_and_otp_cannot_be_replayed(): void
    {
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'wa'])->assertSessionHasNoErrors();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->post('/login/verify', ['otp' => $this->deliveredOtp])->assertRedirect('/owner');
        $owner = User::firstOrFail();
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('HD001', $owner->hanshop_user_id);
        $this->assertNotNull($owner->phone_verified_at);
        $this->post('/logout');
        $this->post('/login/verify', ['otp' => $this->deliveredOtp])->assertSessionHasErrors('otp');
        $this->assertGuest();
    }

    public function test_signup_creates_hanshop_account_only_after_phone_proof(): void
    {
        $this->identity = null;
        $this->post('/login/otp', ['intent' => 'register', 'identifier' => '081234567890', 'name' => 'Owner Baru', 'channel' => 'wa'])->assertSessionHasNoErrors();
        $this->assertFalse($this->registered);
        $this->assertDatabaseCount('users', 0);
        $this->post('/login/verify', ['otp' => '000000'])->assertSessionHasErrors('otp');
        $this->assertFalse($this->registered);
        $this->post('/login/verify', ['otp' => $this->deliveredOtp])->assertRedirect('/owner');
        $this->assertTrue($this->registered);
        $this->assertDatabaseHas('users', ['hanshop_user_id' => 'HD001', 'phone' => '6281234567890', 'role' => 'owner', 'verification_status' => 'unsubmitted']);
    }

    public function test_wrong_otp_attempts_and_expiry_prevent_login(): void
    {
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'wa']);
        $correct = $this->deliveredOtp;
        $wrong = $correct === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login/verify', ['otp' => $wrong])->assertSessionHasErrors('otp');
        }
        $this->post('/login/verify', ['otp' => $correct])->assertSessionHasErrors('otp');
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'wa']);
        $this->travel(6)->minutes();
        $this->post('/login/verify', ['otp' => $this->deliveredOtp])->assertSessionHasErrors('otp');
        $this->assertGuest();
    }

    public function test_unverified_email_cannot_receive_login_otp(): void
    {
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'email'])->assertSessionHasErrors('channel');
        Mail::assertNothingSent();
        $this->assertNull($this->deliveredOtp);
    }

    public function test_email_verification_updates_hanshop_and_local_owner(): void
    {
        $owner = $this->owner('unsubmitted');
        $owner->forceFill(['hanshop_user_id' => 'HD001', 'email_verified_at' => null])->save();
        $this->actingAs($owner)->post('/owner/profile/email/otp', ['email' => 'verified@example.test'])->assertSessionHasNoErrors();
        $challenge = Cache::get('owner-challenge:'.session('owner_challenge_email'));
        $this->assertSame('verified@example.test', $challenge['payload']['email']);
        Mail::assertSent(OwnerOtpMail::class, fn ($mail) => $mail->hasTo('verified@example.test'));
        $rawOtp = null;
        Mail::assertSent(OwnerOtpMail::class, function ($mail) use (&$rawOtp) {
            $rawOtp = $mail->code;

            return true;
        });
        $this->post('/owner/profile/email/verify', ['otp' => $rawOtp])->assertSessionHasNoErrors();
        $this->assertSame('verified@example.test', $owner->fresh()->email);
        $this->assertNotNull($owner->fresh()->email_verified_at);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/profile') && $r['id'] === 'HD001' && $r['email'] === 'verified@example.test');
    }

    public function test_complete_owner_verification_store_and_staff_flow(): void
    {
        $owner = $this->owner('unsubmitted');
        $this->actingAs($owner)->post('/owner/stores', $this->storeInput())->assertForbidden();
        $this->post('/owner/profile/verification', ['birth_place' => 'Bandung', 'birth_date' => '1995-06-15', 'legal_name' => 'Owner Legal', 'nik' => '1234567890123456',
            'address' => 'Alamat test untuk pengujian', 'ktp' => UploadedFile::fake()->image('ktp.jpg')])->assertSessionHasNoErrors();
        $owner->refresh();
        $this->assertSame('pending', $owner->verification_status);
        Storage::disk('local')->assertExists($owner->ktp_path);
        $this->assertStringNotContainsString('1234567890123456', $owner->getRawOriginal('nik'));
        $this->post('/owner/stores', $this->storeInput())->assertForbidden();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin);
        $this->post('/admin/verifications/'.$owner->id, ['decision' => 'approved', 'reference' => $this->submissionReference($owner)])->assertSessionHasNoErrors();
        $this->assertSame('approved', $owner->fresh()->verification_status);
        $owner->refresh();
        $this->actingAs($owner)->post('/owner/stores', $this->storeInput())->assertSessionHasNoErrors();
        $store = Store::firstOrFail();
        $this->assertSame($owner->id, $store->owner_id);
        $this->assertSame('draft', $store->status);
        $this->assertNull($store->expires_at);
        $this->assertFalse($store->acceptsOrders());
        $this->post('/owner/stores/'.$store->id.'/staff', $this->staffInput())->assertSessionHasNoErrors();
        $staff = StoreStaff::firstOrFail();
        $this->assertTrue(Hash::check('Strong-Test-Password-123', $staff->password));
        $this->assertSame($store->id, $staff->store_id);
        $this->assertDatabaseCount('users', 2);
        $this->post('/logout');
        $this->post('/staff/'.$store->id.'/login', ['identifier' => $staff->username, 'password' => 'Strong-Test-Password-123'])->assertRedirect('/staff/'.$store->id.'/dashboard');
        $this->get('/staff/'.$store->id.'/dashboard')->assertOk();
    }

    public function test_rejection_requires_reason_and_document_is_purged_after_72_hours(): void
    {
        $owner = $this->owner('pending');
        $path = UploadedFile::fake()->image('ktp.png')->store('ktp', 'local');
        $owner->forceFill(['ktp_path' => $path])->save();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->post('/admin/verifications/'.$owner->id, ['decision' => 'rejected'])->assertSessionHasErrors('reason');
        $this->post('/admin/verifications/'.$owner->id, ['decision' => 'rejected', 'reference' => $this->submissionReference($owner), 'reason' => 'Foto KTP tidak terbaca'])->assertSessionHasNoErrors();
        $this->travel(71)->hours();
        $this->artisan('digitaltoko:purge-rejected-ktp')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        $this->travel(2)->hours();
        $this->artisan('digitaltoko:purge-rejected-ktp')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($owner->fresh()->ktp_path);
        $this->assertSame('Foto KTP tidak terbaca', $owner->fresh()->rejection_reason);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
    }

    public function test_ktp_and_review_are_private_and_cannot_be_self_approved(): void
    {
        $owner = $this->owner('pending');
        $owner->forceFill(['ktp_path' => UploadedFile::fake()->image('ktp.jpg')->store('ktp', 'local')])->save();
        $other = $this->owner();
        $this->get('/identity/'.$owner->id.'/document')->assertRedirect('/login');
        $this->actingAs($other)->get('/identity/'.$owner->id.'/document')->assertForbidden();
        $this->actingAs($owner)->post('/admin/verifications/'.$owner->id, ['decision' => 'approved'])->assertForbidden();
        $this->get('/identity/'.$owner->id.'/document')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_staff_limit_permissions_and_cross_store_management(): void
    {
        $owner = $this->owner();
        $store = $this->newStore($owner);
        $otherStore = $this->newStore($this->owner(), 'toko-other');
        $this->actingAs($owner);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/owner/stores/'.$store->id.'/staff', $this->staffInput('staff-'.$i))->assertSessionHasNoErrors();
        }
        $this->post('/owner/stores/'.$store->id.'/staff', $this->staffInput('staff-six'))->assertSessionHasErrors('name');
        $this->assertDatabaseCount('store_staff', 5);
        $this->post('/owner/stores/'.$store->id.'/staff', [...$this->staffInput('staff-admin'), 'permissions' => ['admin.manage']])->assertSessionHasErrors('permissions.0');
        $this->post('/owner/stores/'.$otherStore->id.'/staff', $this->staffInput('other'))->assertNotFound();
        $this->get('/owner/stores/'.$otherStore->id.'/manage')->assertNotFound();
        $member = StoreStaff::firstOrFail();
        $this->patch('/owner/stores/'.$otherStore->id.'/staff/'.$member->id, $this->staffInput())->assertNotFound();
    }

    public function test_same_username_in_two_owned_stores_stays_local_and_disabled_staff_cannot_login(): void
    {
        $owner = $this->owner();
        $a = $this->newStore($owner, 'store-a');
        $b = $this->newStore($owner, 'store-b');
        $this->actingAs($owner)->post('/owner/stores/'.$a->id.'/staff', $this->staffInput())->assertSessionHasNoErrors();
        $this->post('/owner/stores/'.$b->id.'/staff', $this->staffInput())->assertSessionHasNoErrors();
        $member = StoreStaff::where('store_id', $a->id)->firstOrFail();
        $this->patch('/owner/stores/'.$a->id.'/staff/'.$member->id, [...$this->staffInput(), 'password' => '', 'active' => false])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Strong-Test-Password-123', $member->fresh()->password));
        $this->post('/logout');
        $this->post('/staff/'.$a->id.'/login', ['identifier' => 'staff-one', 'password' => 'Strong-Test-Password-123'])->assertSessionHasErrors('identifier');
        $this->post('/staff/'.$b->id.'/login', ['identifier' => 'staff-one', 'password' => 'Strong-Test-Password-123'])->assertSessionHasNoErrors();
        $this->get('/staff/'.$a->id.'/dashboard')->assertForbidden();
        $this->get('/owner/profile')->assertRedirect('/login');
    }

    public function test_subdomain_is_reserved_unique_and_cannot_be_changed_via_settings(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/owner/stores', $this->storeInput('admin'))->assertSessionHasErrors('slug');
        $this->post('/owner/stores', $this->storeInput('own-shop'))->assertSessionHasNoErrors();
        $this->post('/owner/stores', $this->storeInput('own-shop'))->assertSessionHasErrors('slug');
        $store = Store::firstOrFail();
        $this->patch('/owner/stores/'.$store->id.'/manage', ['name' => 'Baru', 'template' => 'nebula', 'slug' => 'changed', 'owner_id' => 999])->assertSessionHasNoErrors();
        $this->assertSame('own-shop', $store->fresh()->slug);
        $this->assertSame($owner->id, $store->fresh()->owner_id);
        $this->delete('/owner/profile/identity')->assertConflict();
    }

    public function test_admin_password_login_cannot_be_used_by_owner(): void
    {
        $owner = $this->owner();
        $this->post('/admin/login', ['email' => $owner->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin', 'password' => 'Admin-Test-Password-123'])->save();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Admin-Test-Password-123'])->assertRedirect('/admin');
        $this->get('/admin/verifications')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Admin/Verifications', false));
        $this->get('/owner/profile')->assertForbidden();
    }

    public function test_verified_email_login_and_changed_identity_rejection(): void
    {
        $this->identity['email'] = 'owner@example.test';
        $this->identity['email_verified_at'] = now()->toIso8601String();
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'owner@example.test', 'channel' => 'email'])->assertSessionHasNoErrors();
        $code = null;
        Mail::assertSent(OwnerOtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->post('/login/verify', ['otp' => $code])->assertRedirect('/owner');
        $this->assertAuthenticated();
        $this->post('/logout');
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'wa']);
        $this->identity['phone'] = '6281999999999';
        $this->post('/login/verify', ['otp' => $this->deliveredOtp])->assertSessionHasErrors('otp');
        $this->assertGuest();
    }

    public function test_owner_can_create_multiple_stores_after_one_approval(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/owner/stores', $this->storeInput('first-store'))->assertSessionHasNoErrors();
        $this->post('/owner/stores', $this->storeInput('second-store'))->assertSessionHasNoErrors();
        $this->assertSame(2, Store::where('owner_id', $owner->id)->count());
        $this->assertSame('approved', $owner->fresh()->verification_status);
    }

    public function test_approved_document_is_retained_and_owner_can_clean_only_without_stores(): void
    {
        $owner = $this->owner();
        $path = UploadedFile::fake()->image('approved.png')->store('ktp', 'local');
        $owner->forceFill(['birth_place' => 'Bandung', 'birth_date' => '1995-06-15', 'ktp_path' => $path, 'nik' => '1111222233334444', 'address' => 'Alamat test privat', 'legal_name' => 'Owner Legal'])->save();
        $this->travel(80)->hours();
        $this->artisan('digitaltoko:purge-rejected-ktp')->assertSuccessful();
        Storage::disk('local')->assertExists($path);
        $this->actingAs($owner)->delete('/owner/profile/identity')->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($owner->fresh()->nik);
        $this->assertNull($owner->fresh()->birth_place);
        $this->assertNull($owner->fresh()->birth_date);
        $this->assertSame('unsubmitted', $owner->fresh()->verification_status);
        $this->assertSame($owner->hanshop_user_id, $owner->fresh()->hanshop_user_id);
        Http::assertNothingSent();
    }

    public function test_staff_login_revokes_owner_session_and_permissions_are_enforced(): void
    {
        $owner = $this->owner();
        $store = $this->newStore($owner);
        $this->actingAs($owner)->post('/owner/stores/'.$store->id.'/staff', [...$this->staffInput(), 'permissions' => []])->assertSessionHasNoErrors();
        $this->post('/staff/'.$store->id.'/login', ['identifier' => 'staff-one', 'password' => 'Strong-Test-Password-123'])->assertSessionHasNoErrors();
        $this->assertGuest('web');
        $this->assertAuthenticated('staff');
        $this->get('/owner/profile')->assertRedirect('/login');
        $this->get('/staff/'.$store->id.'/dashboard')->assertForbidden();
        $this->get('/stores/'.$store->id.'/bot-state')->assertForbidden();
    }

    public function test_missing_configuration_and_transport_failure_do_not_create_owner_accounts(): void
    {
        config(['services.hanshop.token' => null]);
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'wa'])->assertSessionHasErrors('identifier');
        $this->assertDatabaseCount('users', 0);
        Http::assertNothingSent();
        config(['services.hanshop.token' => str_repeat('test-', 10)]);
        session()->forget('errors');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('offline'));
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'wa'])->assertSessionHasErrors('identifier');
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(session('owner_challenge_login'));
        $this->assertGuest();
    }

    public function test_missing_verified_contacts_and_invalid_ktp_do_not_submit_identity(): void
    {
        $owner = $this->owner('unsubmitted');
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($owner)->post('/owner/profile/verification', ['birth_place' => 'Bandung', 'birth_date' => '1995-06-15', 'legal_name' => 'Owner', 'nik' => '1234567890123456',
            'address' => 'Alamat simulasi', 'ktp' => UploadedFile::fake()->image('ktp.png')])->assertRedirect('/owner/account');
        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->post('/owner/profile/verification', ['birth_place' => 'Bandung', 'birth_date' => '1995-06-15', 'legal_name' => 'Owner', 'nik' => '123', 'address' => 'Alamat simulasi',
            'ktp' => UploadedFile::fake()->create('document.svg', 10, 'image/svg+xml')])->assertSessionHasErrors(['nik', 'ktp']);
        $this->assertSame('unsubmitted', $owner->fresh()->verification_status);
        $this->assertNull($owner->fresh()->ktp_path);
    }

    public function test_stale_admin_review_cannot_approve_a_resubmitted_document(): void
    {
        $owner = $this->owner('pending');
        $owner->forceFill(['ktp_path' => UploadedFile::fake()->image('old.png')->store('ktp', 'local')])->save();
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin);
        $oldReference = $this->submissionReference($owner);
        $this->get('/identity/'.$owner->id.'/document?reference='.$oldReference)->assertOk();
        $this->post('/admin/verifications/'.$owner->id, ['decision' => 'rejected', 'reason' => 'Dokumen tidak jelas', 'reference' => $oldReference])->assertSessionHasNoErrors();
        $this->actingAs($owner->fresh())->post('/owner/profile/verification', ['birth_place' => 'Bandung', 'birth_date' => '1995-06-15', 'legal_name' => 'Owner Baru', 'nik' => '1111222233334444',
            'address' => 'Alamat simulasi pengajuan baru', 'ktp' => UploadedFile::fake()->image('new.png')])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/verifications/'.$owner->id, ['decision' => 'approved', 'reference' => $oldReference])->assertConflict();
        $this->get('/identity/'.$owner->id.'/document?reference='.$oldReference)->assertConflict();
        $this->assertSame('pending', $owner->fresh()->verification_status);
        $newReference = $this->submissionReference($owner);
        $this->assertNotSame($oldReference, $newReference);
        $this->post('/admin/verifications/'.$owner->id, ['decision' => 'approved', 'reference' => $newReference])->assertSessionHasNoErrors();
        $this->assertSame('approved', $owner->fresh()->verification_status);
    }

    public function test_admin_can_paginate_all_owner_submissions(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->owner('pending');
        }
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->get('/admin/verifications')->assertInertia(fn (Assert $p) => $p
            ->component('Admin/Verifications', false)->has('owners.data', 20)->where('owners.total', 21)->where('owners.last_page', 2));
        $this->get('/admin/verifications?page=2')->assertInertia(fn (Assert $p) => $p->has('owners.data', 1)->where('owners.current_page', 2));
    }

    public function test_email_login_does_not_verify_a_changed_whatsapp_number(): void
    {
        $this->identity['email'] = 'owner@example.test';
        $this->identity['email_verified_at'] = now()->toIso8601String();
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'wa']);
        $this->post('/login/verify', ['otp' => $this->deliveredOtp])->assertRedirect('/owner');
        $owner = User::firstOrFail();
        $this->assertNotNull($owner->phone_verified_at);
        $this->post('/logout');
        $this->identity['phone'] = '6281999999999';
        $this->post('/login/otp', ['intent' => 'login', 'identifier' => 'HD001', 'channel' => 'email'])->assertSessionHasNoErrors();
        $code = null;
        Mail::assertSent(OwnerOtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });
        $this->post('/login/verify', ['otp' => $code])->assertRedirect('/owner');
        $this->assertSame('6281999999999', $owner->fresh()->phone);
        $this->assertNull($owner->fresh()->phone_verified_at);
    }

    public function test_malformed_identity_response_does_not_create_a_challenge_or_account(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['status' => 'SUCCESS', 'data' => ['user' => ['id' => ['invalid']]]]));
        $this->post('/login/otp', ['intent' => 'register', 'identifier' => '081234567890', 'name' => 'Owner', 'channel' => 'wa'])->assertSessionHasErrors('identifier');
        $this->assertDatabaseCount('users', 0);
        $this->assertNull(session('owner_challenge_login'));
    }

    public function test_birth_details_are_required_validated_encrypted_and_visible_to_reviewer(): void
    {
        $owner = $this->owner('unsubmitted');
        $this->actingAs($owner);
        $input = ['legal_name' => 'Nama Lengkap Simulasi', 'address' => 'Alamat lengkap simulasi',
            'ktp' => UploadedFile::fake()->image('ktp.jpg')];
        $this->post('/owner/profile/verification', $input)->assertSessionHasErrors(['birth_place', 'birth_date']);
        $this->post('/owner/profile/verification', [...$input, 'birth_place' => 'Bandung',
            'birth_date' => now()->addDay()->format('Y-m-d')])->assertSessionHasErrors('birth_date');
        $this->assertSame([], Storage::disk('local')->allFiles());
        session()->forget('errors');
        $this->post('/owner/profile/verification', [...$input, 'birth_place' => 'Bandung',
            'birth_date' => '1995-06-15'])->assertSessionHasNoErrors();
        $owner->refresh();
        $this->assertSame('Bandung', $owner->birth_place);
        $this->assertSame('1995-06-15', $owner->birth_date);
        $this->assertNotSame('Bandung', $owner->getRawOriginal('birth_place'));
        $this->assertNotSame('1995-06-15', $owner->getRawOriginal('birth_date'));
        $this->assertArrayNotHasKey('birth_date', $owner->toArray());
        $this->get('/owner/profile')->assertInertia(fn (Assert $page) => $page
            ->where('profile.birth_place', 'Bandung')->where('profile.birth_date', '1995-06-15'));
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin)->get('/admin/verifications')->assertInertia(fn (Assert $page) => $page
            ->where('owners.data.0.birth_place', 'Bandung')->where('owners.data.0.birth_date', '1995-06-15')->missing('owners.data.0.nik'));
    }
}
