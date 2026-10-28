<?php

namespace Tests\Feature;

use App\Http\Middleware\ObserveCommerceWebhook;
use App\Models\User;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemWebhookHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false, 'suppliers.cache_store' => 'array', 'services.bot_manager.token' => null, 'services.hanshop.url' => null]);
        Http::preventStrayRequests();
    }

    public function test_webhook_http_and_business_outcomes_are_aggregated_without_request_secrets(): void
    {
        $observer = app(ObserveCommerceWebhook::class);
        foreach ([[200, 'SUCCESS'], [200, 'REVIEW'], [200, 'IGNORED'], [200, 'ERROR'], [302, ''], [401, ''], [403, ''], [404, ''], [422, ''], [429, ''], [500, '']] as [$code, $status]) {
            $request = Request::create('/api/commerce/otomax/connection/SECRET-CALLBACK-TOKEN?message=SECRET-PRIVATE-MESSAGE', 'GET');
            $observer->terminate($request, response()->json(['status' => $status, 'secret' => 'SECRET-BODY'], $code));
        }
        $this->assertDatabaseCount('system_webhook_health', 11);
        $observer->terminate(Request::create('/api/commerce/otomax/connection/token', 'GET'), response()->json(['status' => 'SUCCESS']));
        $this->assertDatabaseHas('system_webhook_health', ['provider' => 'otomax', 'http_status' => 200, 'outcome' => 'accepted', 'requests' => 2]);
        $data = json_encode(DB::table('system_webhook_health')->get());
        foreach (['SECRET-CALLBACK-TOKEN', 'SECRET-PRIVATE-MESSAGE', 'SECRET-BODY'] as $secret) {
            $this->assertStringNotContainsString($secret, $data);
        }
        $this->assertSame(12, (int) app(SystemHealth::class)->overview()['webhook_requests']);
        $this->assertSame(9, (int) app(SystemHealth::class)->overview()['webhook_errors']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/system-health?tab=webhooks&per_page=20')->assertInertia(fn (Assert $page) => $page->has('items', 11));
    }

    public function test_unknown_api_route_and_wrong_host_rejections_are_observed_too(): void
    {
        $this->postJson('/api/commerce/midtrans/missing-store')->assertNotFound();
        $this->getJson('/api/commerce/otomax/missing/unknown/extra')->assertNotFound();
        $this->postJson('http://unregistered.test/api/commerce/digiflazz', [])->assertNotFound();
        $this->assertDatabaseHas('system_webhook_health', ['provider' => 'midtrans', 'http_status' => 404]);
        $this->assertDatabaseHas('system_webhook_health', ['provider' => 'otomax', 'http_status' => 404]);
        $this->assertDatabaseHas('system_webhook_health', ['provider' => 'digiflazz', 'http_status' => 404]);
    }

    public function test_other_routes_and_methods_are_not_recorded_and_monitoring_failures_do_not_break_response(): void
    {
        $observer = app(ObserveCommerceWebhook::class);
        $observer->terminate(Request::create('/admin/system-health', 'GET'), response('ok'));
        $observer->terminate(Request::create('/api/commerce/digiflazz', 'OPTIONS'), response('', 204));
        $this->assertDatabaseCount('system_webhook_health', 0);
        DB::statement('DROP TABLE system_webhook_health');
        $request = Request::create('/api/commerce/digiflazz', 'POST');
        $response = $observer->handle($request, fn () => response()->json(['status' => 'SUCCESS']));
        $observer->terminate($request, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_webhook_retention_is_seven_days_but_summary_only_uses_last_day(): void
    {
        $base = ['provider' => 'digiflazz', 'http_status' => 200, 'outcome' => 'accepted', 'requests' => 2, 'last_seen_at' => now()];
        DB::table('system_webhook_health')->insert([[...$base, 'bucket' => now()->subDays(8)], [...$base, 'bucket' => now()->subDays(2)], [...$base, 'bucket' => now()]]);
        $this->assertSame(2, (int) app(SystemHealth::class)->overview()['webhook_requests']);
        app(SystemHealth::class)->collect();
        $this->assertDatabaseCount('system_webhook_health', 2);
    }
}
