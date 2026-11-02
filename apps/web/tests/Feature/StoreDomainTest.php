<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StoreDomain;
use App\Models\StoreStaff;
use App\Models\User;
use App\Services\DomainDns;
use App\Support\PlatformHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'digitaltoko.com', 'platform.domain_aliases' => ['hansdigi.biz.id'], 'app.url' => 'https://digitaltoko.com', 'inertia.ssr.enabled' => false,
            'domains.cname_target' => 'shops.digitaltoko.com', 'domains.origin_ips' => ['8.8.8.8'], 'domains.tls_ask_key' => str_repeat('x', 40)]);
        Http::preventStrayRequests();
    }

    private function store(): Store
    {
        $owner = User::factory()->create(['role' => 'owner', 'verification_status' => 'approved']);
        $this->actingAs($owner);

        return Store::create(['owner_id' => $owner->id, 'name' => 'Toko domain', 'slug' => 'domain-'.Store::count(), 'category' => 'full', 'plan' => 'galaxy', 'status' => 'active', 'expires_at' => now()->addDays(30)]);
    }

    private function base(Store $store): string
    {
        return 'https://digitaltoko.com/owner/stores/'.$store->id.'/domain';
    }

    private function claim(Store $store): StoreDomain
    {
        $this->post($this->base($store), ['hostname' => ' SHOP.CUSTOMER.COM. '])->assertSessionHasNoErrors()->assertRedirect();

        return $store->domain()->firstOrFail();
    }

    private function dns(StoreDomain $domain, array $ips = ['8.8.8.8'], bool $txt = true): void
    {
        $this->mock(DomainDns::class, function ($mock) use ($domain, $ips, $txt) {
            $mock->shouldReceive('records')->with('_digitaltoko-domain.'.$domain->hostname, DNS_TXT)->andReturn($txt ? [['entries' => ['digitaltoko=', $domain->verification_token]]] : []);
            $mock->shouldReceive('addresses')->with($domain->hostname)->andReturn($ips);
            $mock->shouldReceive('records')->with($domain->hostname, DNS_CNAME)->andReturn([])->byDefault();
        });
    }

    public function test_claim_is_owner_scoped_one_per_store_and_instructions_follow_configuration(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        $this->assertSame('shop.customer.com', $domain->hostname);
        $this->assertSame('pending_verification', $domain->status);
        $this->assertNull($domain->verified_at);
        $this->assertSame(48, strlen($domain->verification_token));
        $this->get('https://digitaltoko.com/owner/stores/'.$store->id.'/manage')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('domainSettings.txtName', '_digitaltoko-domain.shop.customer.com')->where('domainSettings.txtValue', 'digitaltoko='.$domain->verification_token)
            ->where('domainSettings.target', 'shops.digitaltoko.com')->where('domainSettings.originIps.0', '8.8.8.8'));
        $this->post($this->base($store), ['hostname' => 'another.customer.com'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('store_domains', 1);
        $this->assertNotSame($domain->verification_token, $domain->fresh()->verification_token);
        Http::assertNothingSent();
    }

    public function test_domain_on_another_store_cannot_be_claimed_or_modified(): void
    {
        $first = $this->store();
        $domain = $this->claim($first);
        $second = $this->store();
        $this->postJson($this->base($second), ['hostname' => $domain->hostname])->assertUnprocessable()->assertJsonValidationErrors('hostname');
        $this->post($this->base($first), ['hostname' => 'stolen.customer.com'])->assertNotFound();
        $this->post($this->base($first).'/verify')->assertNotFound();
        $this->delete($this->base($first))->assertNotFound();
        $this->assertSame('shop.customer.com', $domain->fresh()->hostname);
    }

    public function test_staff_unverified_owner_and_bot_only_cannot_manage_domain(): void
    {
        $store = $this->store();
        $store->update(['category' => 'bot']);
        $this->post($this->base($store), ['hostname' => 'shop.customer.com'])->assertRedirect()->assertSessionHasNoErrors();
        $store->update(['category' => 'web']);
        $store->owner->forceFill(['verification_status' => 'pending'])->save();
        $this->actingAs($store->owner)->post($this->base($store), ['hostname' => 'shop.customer.com'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'staff', 'email' => 'staff@test.com', 'password' => 'example-password', 'permissions' => ['web.manage'], 'active' => true]);
        $this->actingAs($staff, 'staff')->post($this->base($store), ['hostname' => 'shop.customer.com'])->assertForbidden();
    }

    public function test_invalid_private_and_platform_hostnames_are_rejected(): void
    {
        $store = $this->store();
        foreach (['https://shop.customer.com/path', '127.0.0.1', 'localhost', 'shop.local', '*.customer.com', 'shop.customer.com:443', 'digitaltoko.com', 'abc.digitaltoko.com', 'abc.hansdigi.biz.id'] as $host) {
            $this->withoutMiddleware(ThrottleRequests::class)->postJson($this->base($store), ['hostname' => $host])->assertUnprocessable()->assertJsonValidationErrors('hostname');
        }
        $this->assertDatabaseCount('store_domains', 0);
    }

    public function test_missing_txt_never_probes_https_or_activates_domain(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        $this->dns($domain, txt: false);
        $this->post($this->base($store).'/verify')->assertSessionHasNoErrors();
        $this->assertSame('pending_verification', $domain->fresh()->status);
        $this->assertStringContainsString('TXT', $domain->fresh()->last_error);
        $this->get('https://shop.customer.com/')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_private_ip_or_wrong_route_never_reaches_http_client(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        $this->dns($domain, ['127.0.0.1']);
        $this->post($this->base($store).'/verify')->assertSessionHasNoErrors();
        $this->assertNull($domain->fresh()->dns_verified_at);
        Http::assertNothingSent();
        $this->dns($domain, ['1.1.1.1']);
        $this->post($this->base($store).'/verify')->assertSessionHasNoErrors();
        $this->assertStringContainsString('Arah DNS', $domain->fresh()->last_error);
        Http::assertNothingSent();
    }

    public function test_https_proof_is_required_and_dns_verified_domain_can_request_tls_only_with_valid_key(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        $this->dns($domain);
        Http::fake(['https://shop.customer.com/api/domains/proof' => Http::response(['hostname' => 'wrong.customer.com', 'token' => $domain->verification_token])]);
        $this->post($this->base($store).'/verify')->assertSessionHasNoErrors();
        $this->assertSame('pending_tls', $domain->fresh()->status);
        $this->assertNull($domain->fresh()->verified_at);
        $this->get('https://shop.customer.com/')->assertNotFound();
        $this->getJson('https://shop.customer.com/api/domains/proof')->assertOk()->assertJsonPath('token', $domain->verification_token);
        $ask = 'http://127.0.0.1/api/domains/tls-ask/';
        $this->get($ask.'invalid?domain=shop.customer.com')->assertForbidden();
        $this->get($ask.str_repeat('x', 40).'?domain=shop.customer.com')->assertOk();
        $this->get($ask.str_repeat('x', 40).'?domain=unknown.customer.com')->assertForbidden();
        $domain->fresh()->update(['dns_verified_at' => null]);
        $this->get($ask.str_repeat('x', 40).'?domain=shop.customer.com')->assertForbidden();
    }

    public function test_dns_and_pinned_https_activate_domain_and_removal_restores_default_url(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        $this->dns($domain);
        Http::fake(function ($request, $options) use ($domain) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame(['shop.customer.com:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);

            return Http::response(['hostname' => $domain->hostname, 'token' => $domain->verification_token]);
        });
        $this->post($this->base($store).'/verify')->assertSessionHasNoErrors();
        $this->assertSame('active', $domain->fresh()->status);
        $this->assertNotNull($domain->fresh()->tls_verified_at);
        $this->get('https://shop.customer.com/')->assertOk();
        config(['app.url' => 'http://localhost:8000']);
        $this->assertSame('https://shop.customer.com', app(PlatformHost::class)->storeUrl($store->fresh()));
        $this->delete($this->base($store))->assertSessionHasNoErrors();
        $this->get('https://shop.customer.com/')->assertNotFound();
        $this->assertDatabaseCount('store_domains', 0);
    }

    public function test_https_connection_failure_keeps_domain_pending_without_claiming_live_success(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        $this->dns($domain);
        Http::fake(fn () => throw new ConnectionException('certificate unavailable'));
        $this->post($this->base($store).'/verify')->assertSessionHasNoErrors();
        $this->assertSame('pending_tls', $domain->fresh()->status);
        $this->assertNull($domain->fresh()->tls_verified_at);
    }

    public function test_expired_unverified_claim_can_be_reclaimed_but_verified_claim_cannot(): void
    {
        $first = $this->store();
        $domain = $this->claim($first);
        $domain->update(['verification_expires_at' => now()->subMinute()]);
        $second = $this->store();
        $this->post($this->base($second), ['hostname' => $domain->hostname])->assertSessionHasNoErrors();
        $this->assertSame($second->id, StoreDomain::firstOrFail()->store_id);
        $this->assertDatabaseCount('store_domains', 1);
        StoreDomain::firstOrFail()->update(['dns_verified_at' => now(), 'verification_expires_at' => now()->subMinute()]);
        $this->actingAs($first->owner)->postJson($this->base($first), ['hostname' => $domain->hostname])->assertUnprocessable()->assertJsonValidationErrors('hostname');
    }

    public function test_domain_edited_during_probe_cannot_be_activated_by_an_old_result(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        $this->dns($domain);
        Http::fake(function () use ($domain) {
            $domain->fresh()->update(['hostname' => 'changed.customer.com', 'status' => 'pending_verification', 'dns_verified_at' => null, 'verification_token' => str_repeat('a', 48)]);

            return Http::response(['hostname' => 'shop.customer.com', 'token' => $domain->getOriginal('verification_token')]);
        });
        $this->postJson($this->base($store).'/verify')->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->assertSame('pending_verification', $domain->fresh()->status);
        $this->assertNull($domain->fresh()->verified_at);
    }

    public function test_tls_permission_for_platform_hosts_is_limited_to_registered_web_stores(): void
    {
        $store = $this->store();
        $ask = 'http://127.0.0.1/api/domains/tls-ask/'.str_repeat('x', 40).'?domain=';
        $this->get($ask.'digitaltoko.com')->assertOk();
        $this->get($ask.$store->slug.'.digitaltoko.com')->assertOk();
        $this->get($ask.'unregistered.digitaltoko.com')->assertForbidden();
        $this->get($ask.'admin.digitaltoko.com')->assertForbidden();
        $store->update(['category' => 'bot']);
        $this->get($ask.$store->slug.'.digitaltoko.com')->assertOk();
        config(['domains.tls_ask_key' => '']);
        $this->get($ask.'digitaltoko.com')->assertForbidden();
    }

    public function test_cname_resolution_uses_public_addresses_of_the_configured_target(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        config(['domains.origin_ips' => []]);
        $this->mock(DomainDns::class, function ($mock) use ($domain) {
            $mock->makePartial();
            $mock->shouldReceive('records')->with('_digitaltoko-domain.'.$domain->hostname, DNS_TXT)->andReturn([['txt' => 'digitaltoko='.$domain->verification_token]]);
            $mock->shouldReceive('records')->with($domain->hostname, DNS_A | DNS_AAAA | DNS_CNAME)->andReturn([['target' => 'shops.digitaltoko.com.']]);
            $mock->shouldReceive('records')->with('shops.digitaltoko.com', DNS_A | DNS_AAAA | DNS_CNAME)->andReturn([['ip' => '8.8.8.8']]);
        });
        Http::fake(['https://shop.customer.com/api/domains/proof' => Http::response(['hostname' => $domain->hostname, 'token' => $domain->verification_token])]);
        $this->post($this->base($store).'/verify')->assertSessionHasNoErrors();
        $this->assertSame('active', $domain->fresh()->status);
    }

    public function test_active_domain_is_disabled_when_ownership_txt_is_removed(): void
    {
        $store = $this->store();
        $domain = $this->claim($store);
        $domain->update(['status' => 'active', 'verified_at' => now(), 'dns_verified_at' => now(), 'tls_verified_at' => now()]);
        $this->dns($domain, txt: false);
        $this->post($this->base($store).'/verify')->assertSessionHasNoErrors();
        $this->assertSame('pending_verification', $domain->fresh()->status);
        $this->get('https://shop.customer.com/')->assertNotFound();
        Http::assertNothingSent();
    }
}
