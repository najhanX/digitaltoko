<?php

namespace Tests\Feature;

use App\Jobs\CheckSystemQueue;
use App\Models\PanelNotification;
use App\Models\Store;
use App\Models\StoreStaff;
use App\Models\SupplierConnection;
use App\Models\User;
use App\Services\FailedJobDiagnostics;
use App\Services\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private bool $dependenciesHealthy = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false,
            'cache.default' => 'array', 'suppliers.cache_store' => 'array', 'services.bot_manager.url' => 'http://127.0.0.1:3099', 'services.bot_manager.token' => str_repeat('x', 40),
            'services.hanshop.url' => 'http://127.0.0.1:4000']);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (! $this->dependenciesHealthy) {
                return Http::response(['token' => 'SECRET-DO-NOT-LEAK', 'error' => 'private exception'], 503);
            }

            return str_ends_with($request->url(), '/health/ready') ? Http::response(['status' => 'SUCCESS', 'data' => ['ready' => true]]) : Http::response(['status' => 'SUCCESS', 'workers' => 2]);
        });
    }

    private function health(): SystemHealth
    {
        return app(SystemHealth::class);
    }

    private function store(array $attributes = []): Store
    {
        return Store::create([...['owner_id' => User::factory()->create(['role' => 'owner'])->id, 'name' => 'Toko sehat', 'slug' => 'health-'.Str::lower(Str::random(10)),
            'category' => 'full', 'status' => 'active', 'expires_at' => now()->addMonth(), 'bot_connection_id' => (string) Str::uuid()], ...$attributes]);
    }

    private function services(): array
    {
        return collect($this->health()->overview()['services'])->keyBy('id')->all();
    }

    public function test_only_admin_can_view_or_refresh_system_health(): void
    {
        $this->get('/admin/system-health')->assertRedirect('/admin/login');
        $this->post('/admin/system-health/refresh')->assertRedirect('/admin/login');
        $this->post('/admin/system-health/queue-probe')->assertRedirect('/admin/login');
        $owner = User::factory()->create(['role' => 'owner']);
        $this->actingAs($owner)->get('/admin/system-health')->assertForbidden();
        $this->post('/admin/system-health/refresh')->assertForbidden();
        $store = $this->store();
        $staff = StoreStaff::create(['store_id' => $store->id, 'name' => 'Staff', 'username' => 'health-staff', 'email' => 'staff@health.test', 'password' => 'password-test', 'active' => true, 'permissions' => ['dashboard.view']]);
        $this->app['auth']->forgetGuards();
        $this->actingAs($staff, 'staff')->get('/admin/system-health')->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'web')->get('/admin/system-health')->assertInertia(fn (Assert $page) => $page->component('Admin/SystemHealth')->has('overview.services', 6)->where('workspace.role', 'admin'));
        Http::assertNothingSent();
    }

    public function test_manual_check_probes_read_only_dependencies_without_faking_scheduler_or_worker(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->assertTrue($this->health()->collect());
        $services = $this->services();
        foreach (['database', 'cache', 'bot_manager', 'hanshop_api'] as $id) {
            $this->assertSame('ok', $services[$id]['status']);
        }
        $this->assertSame('unknown', $services['scheduler']['status']);
        $this->assertSame('unknown', $services['queue_worker']['status']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/health') && $request->hasHeader('Authorization', 'Bearer '.str_repeat('x', 40)));
        $this->assertDatabaseMissing('system_health_signals', ['name' => 'scheduler']);
        $this->assertDatabaseMissing('system_health_signals', ['name' => 'queue_worker']);
    }

    public function test_missing_configuration_and_remote_failures_are_not_reported_healthy_or_leaked(): void
    {
        config(['services.bot_manager.token' => null, 'services.hanshop.url' => null]);
        $this->health()->collect();
        $this->assertSame('unknown', $this->services()['bot_manager']['status']);
        $this->assertSame('unknown', $this->services()['hanshop_api']['status']);
        Http::assertNothingSent();
        config(['services.bot_manager.token' => str_repeat('x', 40), 'services.hanshop.url' => 'http://127.0.0.1:4000']);
        $this->dependenciesHealthy = false;
        $this->health()->collect();
        $this->assertSame('error', $this->services()['bot_manager']['status']);
        $this->assertSame('error', $this->services()['hanshop_api']['status']);
        $this->assertStringNotContainsString('SECRET-DO-NOT-LEAK', json_encode(DB::table('system_health_signals')->get()));
    }

    public function test_scheduler_and_worker_evidence_expires_and_sync_driver_never_proves_background_worker(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->health()->schedulerTick();
        $this->health()->workerLoop(new Looping('database', 'default'));
        $this->assertSame('ok', $this->services()['scheduler']['status']);
        $this->assertSame('ok', $this->services()['queue_worker']['status']);
        $this->travel(6)->minutes();
        $this->assertSame('warning', $this->services()['scheduler']['status']);
        $this->assertSame('warning', $this->services()['queue_worker']['status']);
        $this->health()->workerLoop(new Looping('database', 'other'));
        $this->assertSame('warning', $this->services()['queue_worker']['status']);
        config(['queue.default' => 'sync']);
        $this->assertSame('warning', $this->services()['queue_worker']['status']);
    }

    public function test_probe_is_bounded_idempotent_and_old_job_cannot_complete_new_probe(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->health()->enqueueProbe();
        $first = json_decode(DB::table('system_health_signals')->where('name', 'queue_probe')->value('details'), true)['token'];
        $this->health()->enqueueProbe();
        Queue::assertPushed(CheckSystemQueue::class, 1);
        $this->health()->completeProbe('wrong-token');
        $this->assertTrue($this->health()->overview()['queue']['probe_pending']);
        $this->health()->completeProbe($first);
        $this->health()->completeProbe($first);
        $this->assertFalse($this->health()->overview()['queue']['probe_pending']);
        $this->health()->enqueueProbe();
        Queue::assertPushed(CheckSystemQueue::class, 2);
        $this->health()->completeProbe($first);
        $this->assertTrue($this->health()->overview()['queue']['probe_pending']);
        $this->health()->failProbe($first);
        $this->assertTrue($this->health()->overview()['queue']['probe_pending']);
        $token = json_decode(DB::table('system_health_signals')->where('name', 'queue_probe')->value('details'), true)['token'];
        (new CheckSystemQueue($token))->failed(new \RuntimeException('private error'));
        $this->assertFalse($this->health()->overview()['queue']['probe_pending']);
        $this->assertSame('error', $this->health()->overview()['queue']['probe_status']);
        $this->health()->enqueueProbe();
        Queue::assertPushed(CheckSystemQueue::class, 3);
    }

    public function test_delayed_probe_warns_even_when_worker_loop_is_fresh(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->health()->enqueueProbe();
        $this->travel(4)->minutes();
        $this->health()->workerLoop(new Looping('database', 'default'));
        $this->assertSame('warning', $this->services()['queue_worker']['status']);
        $this->assertStringContainsString('Uji antrean', $this->services()['queue_worker']['message']);
    }

    public function test_admin_can_replace_a_lost_probe_without_retrying_business_jobs_or_accepting_old_results(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->health()->enqueueProbe();
        $token = json_decode(DB::table('system_health_signals')->where('name', 'queue_probe')->value('details'), true)['token'];
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post('/admin/system-health/queue-probe')->assertSessionHasErrors('queue');
        Queue::assertPushed(CheckSystemQueue::class, 1);
        $this->travel(4)->minutes();
        $this->assertTrue($this->health()->overview()['queue']['probe_retry_available']);
        $this->post('/admin/system-health/queue-probe')->assertSessionHasNoErrors();
        Queue::assertPushed(CheckSystemQueue::class, 2);
        $this->health()->completeProbe($token);
        $this->assertTrue($this->health()->overview()['queue']['probe_pending']);
        $this->assertFalse($this->health()->overview()['queue']['probe_retry_available']);
        $this->assertDatabaseCount('customer_orders', 0);
        Http::assertNothingSent();
    }

    public function test_configured_database_queue_counts_do_not_mix_other_queues_or_expose_payloads(): void
    {
        config(['queue.default' => 'database']);
        $base = ['queue' => 'default', 'payload' => 'SECRET-JOB-PAYLOAD', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp];
        DB::table('jobs')->insert([$base, [...$base, 'reserved_at' => now()->timestamp], [...$base, 'available_at' => now()->addHour()->timestamp], [...$base, 'queue' => 'other']]);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => 'SECRET-JOB-PAYLOAD', 'exception' => 'SECRET-EXCEPTION', 'failed_at' => now()]);
        $stats = $this->health()->queueMetrics();
        $this->assertSame(1, $stats['ready']);
        $this->assertSame(1, $stats['reserved']);
        $this->assertSame(1, $stats['delayed']);
        $this->assertSame(1, $stats['failed']);
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['queue', 'failed'] as $tab) {
            $this->actingAs($admin)->get('/admin/system-health?tab='.$tab)->assertOk()->assertDontSee('SECRET-JOB-PAYLOAD')->assertDontSee('SECRET-EXCEPTION');
        }
    }

    public function test_unavailable_queue_storage_does_not_crash_health_page(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.table' => 'missing_queue_table']);
        $this->assertTrue($this->health()->queueMetrics()['unavailable']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/system-health?tab=queue')
            ->assertInertia(fn (Assert $page) => $page->where('listingUnavailable', true)->where('overview.queue.unavailable', true));
    }

    public function test_failed_wallet_jobs_show_safe_causes_and_role_specific_recovery_steps(): void
    {
        $payload = json_encode(['displayName' => 'App\\Jobs\\RefreshSupplierWallet', 'data' => ['command' => 'SECRET-SERIALIZED-COMMAND', 'phone' => 'SECRET-CONTACT']]);
        foreach (['Endpoint pemasok Hanshop belum tersedia.', 'Pembacaan akun Hanshop ditolak.'] as $message) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => $payload,
                'exception' => 'App\\Services\\SupplierFailure: '.$message.' SECRET-TOKEN in SECRET-PATH:32', 'failed_at' => now()]);
        }
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/system-health?tab=failed');
        $response->assertInertia(fn (Assert $page) => $page->has('items', 2)
            ->where('items.0.label', 'Pembaruan saldo pemasok')->where('items.0.reference', 'Job gagal #1')
            ->where('items.0.message', 'Endpoint pemasok Hanshop belum tersedia saat proses dijalankan.')
            ->where('items.0.next_steps.0', fn ($value) => str_contains($value, 'Administrator:') && str_contains($value, 'HANSHOP_API_URL'))
            ->where('items.0.next_steps.1', fn ($value) => str_contains($value, 'Owner:') && str_contains($value, 'Saldo & deposit pemasok'))
            ->where('items.0.impact', fn ($value) => str_contains($value, 'tidak menyatakan transaksi pelanggan gagal'))
            ->where('items.1.message', 'Hanshop menolak pembacaan akun saat proses dijalankan.')
            ->where('items.1.next_steps.0', fn ($value) => str_contains($value, 'masa aktif toko')));
        foreach (['SECRET-SERIALIZED-COMMAND', 'SECRET-CONTACT', 'SECRET-TOKEN', 'SECRET-PATH', 'diagnostic_payload', 'diagnostic_exception'] as $secret) {
            $response->assertDontSee($secret);
        }
        $this->assertDatabaseCount('failed_jobs', 2);
        Http::assertNothingSent();
    }

    public function test_unknown_job_or_exception_uses_safe_fallback_and_never_treats_stack_text_as_cause(): void
    {
        $diagnostics = app(FailedJobDiagnostics::class);
        $unknown = $diagnostics->describe('not json SECRET-PAYLOAD', "RuntimeException: SECRET-ERROR\nApp\\Services\\SupplierFailure: Endpoint pemasok Hanshop belum tersedia.");
        $this->assertSame('Proses latar belakang', $unknown['process']);
        $this->assertStringContainsString('belum dapat dikenali', $unknown['reason']);
        $this->assertStringNotContainsString('SECRET', json_encode($unknown));
        $this->assertStringNotContainsString('Endpoint pemasok Hanshop', json_encode($unknown));
        $timeout = $diagnostics->describe(json_encode(['displayName' => 'App\\Jobs\\SendCustomerNotice']), 'Illuminate\\Queue\\TimeoutExceededException: SECRET-PHONE');
        $this->assertSame('Pengiriman notifikasi pelanggan', $timeout['process']);
        $this->assertSame('Proses melewati batas waktu yang diizinkan.', $timeout['reason']);
        $this->assertStringContainsString('tidak otomatis berarti pesanan', $timeout['impact']);
        $this->assertStringNotContainsString('SECRET', json_encode($timeout));
    }

    public function test_redis_queue_statistics_use_counts_without_reading_job_payloads(): void
    {
        config(['queue.default' => 'redis']);
        $redis = \Mockery::mock(RedisQueue::class);
        $redis->shouldReceive('pendingSize')->with('default')->once()->andReturn(3);
        $redis->shouldReceive('reservedSize')->with('default')->once()->andReturn(2);
        $redis->shouldReceive('delayedSize')->with('default')->once()->andReturn(1);
        Queue::shouldReceive('connection')->with('redis')->once()->andReturn($redis);
        $stats = $this->health()->queueMetrics();
        $this->assertSame([3, 2, 1], [$stats['ready'], $stats['reserved'], $stats['delayed']]);
        $this->assertNull($stats['oldest_at']);
    }

    public function test_supplier_failure_staleness_and_manual_updates_are_distinguished_without_secrets(): void
    {
        $store = $this->store(['bot_connection_id' => null]);
        $manual = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'name' => 'Manual', 'enabled' => true,
            'synced_at' => now(), 'catalog_config' => ['automatic' => false, 'url' => 'https://secret.test/token']]);
        $late = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'otomax', 'slot' => 'late', 'name' => 'Terlambat', 'enabled' => true,
            'synced_at' => now()->subHour(), 'catalog_next_sync_at' => now()->subMinutes(6), 'catalog_config' => ['automatic' => true]]);
        $failed = SupplierConnection::create(['store_id' => $store->id, 'kind' => 'hanshop', 'enabled' => true, 'last_error' => 'secret.test/token']);
        $this->health()->collect();
        $this->assertDatabaseHas('system_health_signals', ['name' => 'supplier:'.$manual->id, 'status' => 'unknown']);
        $this->assertDatabaseHas('system_health_signals', ['name' => 'supplier:'.$late->id, 'status' => 'warning']);
        $this->assertDatabaseHas('system_health_signals', ['name' => 'supplier:'.$failed->id, 'status' => 'error']);
        $this->assertStringNotContainsString('secret.test', json_encode(DB::table('system_health_signals')->get()));
    }

    public function test_observation_timestamps_are_normalized_to_utc_without_mysql_implicit_updates(): void
    {
        $this->health()->record('timestamp-test', 'internal', 'Waktu', 'ok', observedAt: '2026-10-28T16:00:00+07:00');
        $this->assertSame('2026-10-28 09:00:00', DB::table('system_health_signals')->where('name', 'timestamp-test')->value('observed_at'));
    }

    public function test_store_signals_require_fresh_matching_sessions_and_exclude_inactive_stores(): void
    {
        $store = $this->store();
        $inactive = $this->store(['status' => 'suspended']);
        Cache::put('bot:'.$store->id.':state', ['connection_id' => $store->bot_connection_id, 'status' => 'CONNECTED', 'updated_at' => now()->toIso8601String(), 'phone' => 'SECRET-PHONE', 'pairing_code' => 'SECRET-PAIRING']);
        SupplierConnection::create(['store_id' => $store->id, 'kind' => 'digiflazz', 'enabled' => true, 'synced_at' => now()->subMinutes(11), 'credentials' => ['key' => 'SECRET-API']]);
        $this->health()->collect();
        $this->assertDatabaseHas('system_health_signals', ['name' => 'bot:'.$store->id, 'status' => 'ok']);
        $this->assertDatabaseMissing('system_health_signals', ['store_id' => $inactive->id]);
        $this->assertDatabaseHas('system_health_signals', ['group' => 'suppliers', 'status' => 'warning']);
        $signals = json_encode(DB::table('system_health_signals')->get());
        foreach (['SECRET-PHONE', 'SECRET-PAIRING', 'SECRET-API'] as $secret) {
            $this->assertStringNotContainsString($secret, $signals);
        }
        Cache::put('bot:'.$store->id.':state', ['connection_id' => (string) Str::uuid(), 'status' => 'CONNECTED', 'updated_at' => now()->toIso8601String()]);
        $this->health()->collect();
        $this->assertDatabaseHas('system_health_signals', ['name' => 'bot:'.$store->id, 'status' => 'unknown']);
        Cache::put('bot:'.$store->id.':state', ['connection_id' => $store->bot_connection_id, 'status' => 'CONNECTED', 'updated_at' => 'bad timestamp']);
        $this->health()->collect();
        $this->assertDatabaseHas('system_health_signals', ['name' => 'bot:'.$store->id, 'status' => 'unknown']);
    }

    public function test_cache_outage_still_records_other_services_and_does_not_abort_collector(): void
    {
        $this->store();
        Cache::shouldReceive('store')->andReturnSelf();
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('private cache error'));
        Cache::shouldReceive('forget')->andReturn(true);
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('private cache error'));
        $this->assertTrue($this->health()->collect());
        $this->assertSame('error', $this->services()['cache']['status']);
        $this->assertSame('ok', $this->services()['bot_manager']['status']);
        $this->assertDatabaseHas('system_health_signals', ['group' => 'bots', 'status' => 'unknown']);
    }

    public function test_list_search_pagination_and_effective_stale_filter_are_server_side(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        for ($i = 0; $i < 17; $i++) {
            $this->health()->record('demo:'.$i, 'bots', 'Toko '.$i, 'ok', ['source' => 'WhatsApp toko', 'message' => 'Terhubung']);
        }
        DB::table('system_health_signals')->where('name', 'demo:16')->update(['checked_at' => now()->subMinutes(5)]);
        $this->actingAs($admin)->get('/admin/system-health?per_page=5&page=2')->assertInertia(fn (Assert $page) => $page->has('items', 5)->where('pagination.current_page', 2)->where('pagination.total', 17));
        $this->get('/admin/system-health?q=Toko+16')->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.status', 'unknown'));
        $this->get('/admin/system-health?status=ok')->assertInertia(fn (Assert $page) => $page->where('pagination.total', 16));
        $this->get('/admin/system-health?status=unknown')->assertInertia(fn (Assert $page) => $page->where('pagination.total', 1));
        $this->get('/admin/system-health?page=999999&per_page=5')->assertInertia(fn (Assert $page) => $page->where('pagination.current_page', 4)->has('items', 2));
    }

    public function test_collector_lease_prevents_concurrent_probe_and_notifications_are_deduplicated_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'owner']);
        $this->health()->record('collector_lock', 'internal', 'Collector', 'busy', ['token' => 'another-check']);
        $this->assertFalse($this->health()->collect());
        Http::assertNothingSent();
        $this->travel(46)->seconds();
        config(['queue.default' => 'database']);
        Queue::fake();
        $this->health()->record('scheduler', 'core', 'Scheduler', 'ok');
        $this->health()->workerLoop(new Looping('database', 'default'));
        $this->dependenciesHealthy = false;
        $this->health()->collect();
        $this->health()->collect();
        $this->assertSame(1, PanelNotification::where('user_id', $admin->id)->count());
        $this->assertSame(0, PanelNotification::where('user_id', $owner->id)->count());
        $this->dependenciesHealthy = true;
        $this->health()->collect();
        $this->assertSame(2, PanelNotification::where('user_id', $admin->id)->count());
        $this->assertDatabaseHas('panel_notifications', ['user_id' => $admin->id, 'title' => 'Kesehatan sistem pulih']);
    }

    public function test_enabled_backup_health_is_visible_to_admin_without_credentials(): void
    {
        config(['backup.enabled' => true, 'backup.directory' => storage_path('framework/testing/no-backups-'.Str::uuid()), 'backup.key' => 'SECRET-BACKUP-KEY']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/system-health')
            ->assertInertia(fn (Assert $page) => $page->has('overview.services', 7)->where('overview.services.6.id', 'backup')->where('overview.services.6.status', 'warning'))
            ->assertDontSee('SECRET-BACKUP-KEY');
    }
}
