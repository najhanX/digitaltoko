<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreStaff;
use App\Models\User;
use App\Support\PlatformHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlatformFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'hansdigi.biz.id', 'app.url' => 'https://hansdigi.biz.id']);
    }

    private function store(?User $owner = null, string $slug = 'studio'): Store
    {
        return Store::create(['owner_id' => ($owner ?? User::factory()->create())->id,
            'name' => 'Studio Digital', 'slug' => $slug, 'category' => 'full', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
    }

    public function test_platform_domain_changes_without_rewriting_store_slugs(): void
    {
        $store = $this->store();
        $this->get('https://studio.hansdigi.biz.id/')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Storefront/Home', false)->where('store.id', $store->id));
        config(['platform.domain' => 'digitaltoko.com', 'app.url' => 'https://digitaltoko.com']);
        $this->get('https://studio.digitaltoko.com/')->assertOk();
        $this->get('https://studio.hansdigi.biz.id/')->assertNotFound();
        $this->assertSame('https://studio.digitaltoko.com', app(PlatformHost::class)->storeUrl($store));
        $this->assertSame('studio', $store->fresh()->slug);
    }

    public function test_artisan_serve_local_addresses_can_open_login(): void
    {
        $this->app['env'] = 'local';
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost:8000']);
        foreach (['localhost', '127.0.0.1'] as $host) {
            $this->get('http://'.$host.':8000/login')->assertOk()
                ->assertInertia(fn (Assert $p) => $p->component('Auth/Login', false));
        }
        $this->get('http://unknown.example:8000/login')->assertNotFound();
    }

    public function test_local_aliases_do_not_bypass_production_domain_checks(): void
    {
        $this->app['env'] = 'production';
        foreach (['localhost', '127.0.0.1'] as $host) {
            $this->get('http://'.$host.'/login')->assertNotFound();
        }
        $this->get('https://hansdigi.biz.id/login')->assertOk();
    }

    public function test_only_verified_active_custom_domain_can_open_store(): void
    {
        $store = $this->store();
        $domain = StoreDomain::create(['store_id' => $store->id, 'hostname' => 'shop.customer.example']);
        $this->get('https://shop.customer.example/')->assertNotFound();
        $domain->update(['status' => 'active', 'verified_at' => now()]);
        $this->get('https://shop.customer.example/')->assertOk();
        config(['platform.domain' => 'digitaltoko.com', 'app.url' => 'https://digitaltoko.com']);
        $this->assertSame('https://shop.customer.example', app(PlatformHost::class)->storeUrl($store->fresh()));
    }

    public function test_explicit_old_domain_alias_preserves_access_during_migration(): void
    {
        $this->store();
        config(['platform.domain' => 'digitaltoko.com', 'app.url' => 'https://digitaltoko.com', 'platform.domain_aliases' => ['hansdigi.biz.id']]);
        $this->get('https://studio.hansdigi.biz.id/')->assertOk();
        $this->get('https://studio.digitaltoko.com/')->assertOk();
        $this->get('https://hansdigi.biz.id/')->assertOk();
    }

    public function test_unknown_nested_and_reserved_hosts_are_rejected(): void
    {
        $this->store();
        foreach (['evil.example', 'missing.hansdigi.biz.id', 'nested.studio.hansdigi.biz.id', 'admin.hansdigi.biz.id'] as $host) {
            $this->get('https://'.$host.'/')->assertNotFound();
        }
    }

    public function test_untrusted_forwarded_host_does_not_select_tenant(): void
    {
        $this->store();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->withHeader('X-Forwarded-Host', 'studio.hansdigi.biz.id')
            ->get('https://hansdigi.biz.id/')->assertInertia(fn (Assert $p) => $p->component('Platform/Home', false));
    }

    public function test_owner_only_sees_own_stores_and_cannot_open_another_owner_store(): void
    {
        $owner = User::factory()->create();
        $mine = $this->store($owner);
        $other = $this->store(null, 'another');
        $this->actingAs($owner)->get('https://hansdigi.biz.id/owner')->assertInertia(fn (Assert $p) => $p
            ->component('Panel/Dashboard', false)->has('stores', 1)->where('stores.0.id', $mine->id));
        $this->get('https://hansdigi.biz.id/owner/stores/'.$other->id)->assertForbidden();
        $this->get('https://hansdigi.biz.id/admin')->assertForbidden();
    }

    public function test_admin_routes_cannot_be_opened_from_store_hostname(): void
    {
        $this->store();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('https://hansdigi.biz.id/admin')->assertOk();
        $this->get('https://studio.hansdigi.biz.id/admin')->assertNotFound();
    }

    public function test_staff_is_local_and_cannot_access_another_store(): void
    {
        $a = $this->store();
        $b = $this->store(null, 'another');
        $staff = StoreStaff::create(['store_id' => $a->id, 'name' => 'Staff', 'username' => 'operator',
            'email' => 'staff@example.test', 'password' => 'test-only-password', 'permissions' => ['dashboard.view']]);
        $this->assertDatabaseMissing('users', ['email' => 'staff@example.test']);
        $this->actingAs($staff, 'staff')->get('https://hansdigi.biz.id/staff/'.$a->id.'/dashboard')->assertOk();
        $this->get('https://hansdigi.biz.id/staff/'.$b->id.'/dashboard')->assertForbidden();
        $staff->update(['active' => false]);
        $this->get('https://hansdigi.biz.id/staff/'.$a->id.'/dashboard')->assertForbidden();
    }

    public function test_staff_password_login_is_scoped_to_requested_store(): void
    {
        $a = $this->store();
        $b = $this->store(null, 'another');
        StoreStaff::create(['store_id' => $a->id, 'name' => 'Staff', 'username' => 'operator', 'email' => 'staff@example.test', 'password' => 'secret-test']);
        $this->post('https://hansdigi.biz.id/staff/'.$b->id.'/login', ['identifier' => 'operator', 'password' => 'secret-test'])
            ->assertSessionHasErrors('identifier');
        $this->post('https://hansdigi.biz.id/staff/'.$a->id.'/login', ['identifier' => 'staff@example.test', 'password' => 'secret-test'])
            ->assertRedirect('/staff/'.$a->id.'/dashboard');
    }

    public function test_paid_grace_and_trial_expiry_have_different_access_boundaries(): void
    {
        $this->travelTo(now()->startOfSecond());
        $store = $this->store();
        $store->update(['expires_at' => now()->subDays(6), 'status' => 'grace']);
        $this->assertTrue($store->acceptsOrders());
        $store->update(['is_trial' => true]);
        $this->assertFalse($store->acceptsOrders());
        $store->update(['is_trial' => false, 'expires_at' => now()->subDays(7)]);
        $this->assertFalse($store->acceptsOrders());
        $store->update(['status' => 'suspended', 'expires_at' => now()->addDays(30)]);
        $this->assertFalse($store->acceptsOrders());
    }

    public function test_worker_body_cannot_spoof_store_and_invalid_tokens_are_denied(): void
    {
        $a = $this->store();
        $b = $this->store(null, 'another');
        $token = str_repeat('a', 64);
        $a->update(['worker_token_hash' => hash('sha256', $token)]);
        $this->postJson('https://hansdigi.biz.id/api/internal/bot/events', ['status' => 'CONNECTED'])->assertUnauthorized();
        $this->withToken($token)->postJson('https://hansdigi.biz.id/api/internal/bot/events', ['status' => 'CONNECTED', 'store_id' => $b->id])->assertOk();
        $this->assertSame('CONNECTED', Cache::get('bot:'.$a->id.':state')['status']);
        $this->assertNull(Cache::get('bot:'.$b->id.':state'));
        $this->withToken($token)->getJson('https://hansdigi.biz.id/api/internal/bot/access')->assertJsonPath('data.allowed', true);
        $a->update(['status' => 'suspended']);
        $this->withToken($token)->getJson('https://hansdigi.biz.id/api/internal/bot/access')->assertJsonPath('data.can_connect', false);
    }

    public function test_preview_is_unavailable_in_production_even_when_flag_is_enabled(): void
    {
        config(['platform.preview_enabled' => true]);
        $this->app['env'] = 'production';
        $this->get('https://hansdigi.biz.id/preview/admin')->assertNotFound();
    }

    public function test_pairing_state_is_private_to_owner_or_authorized_local_staff(): void
    {
        $owner = User::factory()->create();
        $a = $this->store($owner);
        $b = $this->store(null, 'another');
        Cache::put('bot:'.$a->id.':state', ['status' => 'PAIRING', 'qr' => 'test-qr'], 90);
        $this->getJson('https://hansdigi.biz.id/stores/'.$a->id.'/bot-state')->assertForbidden();
        $this->actingAs($owner)->getJson('https://hansdigi.biz.id/stores/'.$a->id.'/bot-state')->assertJsonPath('data.qr', 'test-qr');
        $this->getJson('https://hansdigi.biz.id/stores/'.$b->id.'/bot-state')->assertForbidden();
    }

    public function test_session_cookie_is_host_only(): void
    {
        $response = $this->get('https://hansdigi.biz.id/login');
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertEmpty($cookie->getDomain());
        }
        $this->assertNull(config('session.domain'));
    }

    public function test_bot_only_store_has_no_public_web_storefront(): void
    {
        $store = $this->store();
        $store->update(['category' => 'bot']);
        $this->get('https://studio.hansdigi.biz.id/')->assertNotFound();
    }
}
