<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\BackupRecovery;
use App\Services\LiveBackupRestore;
use App\Services\PlatformBackup;
use App\Services\RecoveryProcesses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class LiveBackupRestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $id;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/live-restore-test-'.Str::uuid());
        $this->id = (string) Str::uuid();
        File::ensureDirectoryExists($this->root, 0700);
        $sources = [];
        foreach (['private', 'public', 'whatsapp'] as $alias) {
            $sources[$alias] = $this->root.'/source/'.$alias;
            File::ensureDirectoryExists($sources[$alias], 0700);
            file_put_contents($sources[$alias].'/old-only.txt', 'BEFORE');
        }
        config(['backup.live_enabled' => true, 'backup.directory' => $this->root.'/archives', 'backup.sources' => $sources,
            'backup.restore_lock' => $this->root.'/lease.lock', 'backup.restore_hold' => $this->root.'/hold.json', 'platform.domain' => 'localhost', 'app.url' => 'http://localhost']);
        $this->data = ['id' => $this->id, 'mode' => 'active', 'database' => 'dt_restore_'.str_replace('-', '', $this->id), 'source' => 'fixture.zip', 'requested_at' => now()->toIso8601String(), 'deadline' => now()->addHour()->toIso8601String()];
        app(LiveBackupRestore::class)->record($this->id, [...$this->data, 'state' => 'queued', 'phase' => 'Menunggu worker']);
        file_put_contents(app(BackupRecovery::class)->input($this->id), 'FAKE ARCHIVE - mocked verifier');
    }

    protected function tearDown(): void
    {
        $path = realpath($this->root);
        if ($path && dirname($path) === realpath(storage_path('framework/testing')) && str_starts_with(basename($path), 'live-restore-test-')) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    private function backup(): PlatformBackup
    {
        return $this->partialMock(PlatformBackup::class, function ($mock): void {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('verify')->andReturn(['app_key' => config('app.key')]);
            $mock->shouldReceive('restore')->once()->andReturnUsing(function ($input, $database, $folder): void {
                foreach (['private', 'public', 'whatsapp'] as $alias) {
                    File::ensureDirectoryExists($folder.'/files/'.$alias, 0700);
                    file_put_contents($folder.'/files/'.$alias.'/recovered.txt', 'RECOVERED');
                }
            });
        });
    }

    public function test_live_flow_keeps_writers_paused_and_requires_review_before_resume(): void
    {
        $backup = $this->backup();
        $backup->shouldReceive('create')->once()->andReturn($this->root.'/before.zip');
        $backup->shouldReceive('replaceActiveDatabase')->once();
        $processes = $this->mock(RecoveryProcesses::class);
        $states = array_fill_keys(RecoveryProcesses::NAMES, 'online');
        $processes->shouldReceive('inspect')->once()->andReturn($states);
        $processes->shouldReceive('down')->once();
        $processes->shouldReceive('pause')->once()->with($states);
        $processes->shouldReceive('up')->once();
        app(LiveBackupRestore::class)->run($this->data, $backup, $processes);
        $this->assertFileExists(config('backup.restore_hold'));
        $this->assertSame('completed', app(LiveBackupRestore::class)->read($this->id)['state']);
        foreach (config('backup.sources') as $alias => $path) {
            $this->assertSame('RECOVERED', file_get_contents($path.'/recovered.txt'));
            $this->assertFileDoesNotExist($path.'/old-only.txt');
            $this->assertFileExists(dirname($path).'/.old-'.$alias.'-'.substr(str_replace('-', '', $this->id), 0, 12).'/old-only.txt');
        }
        $this->postJson('/anything')->assertStatus(423);
        $processes->shouldReceive('resume')->once()->with($states);
        app(LiveBackupRestore::class)->resume($this->id, $processes);
        $this->assertFileDoesNotExist(config('backup.restore_hold'));
    }

    public function test_failure_before_replacement_resumes_original_services(): void
    {
        $owner = User::factory()->create();
        $store = Store::create(['owner_id' => $owner->id, 'slug' => 'busy-store', 'name' => 'Busy']);
        SubscriptionInvoice::create(['store_id' => $store->id, 'owner_id' => $owner->id, 'purpose' => 'initial', 'snapshot' => []]);
        $backup = $this->backup();
        $backup->shouldNotReceive('create');
        $backup->shouldNotReceive('replaceActiveDatabase');
        $processes = $this->mock(RecoveryProcesses::class);
        $processes->shouldReceive('inspect')->andReturn([]);
        $processes->shouldReceive('down')->once();
        $processes->shouldReceive('pause')->once();
        $processes->shouldReceive('resume')->once();
        $processes->shouldReceive('up')->once();
        try {
            app(LiveBackupRestore::class)->run($this->data, $backup, $processes);
            $this->fail('Pending invoice accepted');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('transaksi/pembayaran aktif', $error->getMessage());
        }
        $this->assertFileDoesNotExist(config('backup.restore_hold'));
        $this->assertSame('BEFORE', file_get_contents(config('backup.sources.private').'/old-only.txt'));
    }

    public function test_failure_during_replacement_keeps_maintenance_and_safety_backup_reference(): void
    {
        $backup = $this->backup();
        $backup->shouldReceive('create')->andReturn($this->root.'/before.zip');
        $backup->shouldReceive('replaceActiveDatabase')->andThrow(new \RuntimeException('Import failed'));
        $processes = $this->mock(RecoveryProcesses::class);
        $processes->shouldReceive('inspect')->andReturn([]);
        $processes->shouldReceive('down')->once();
        $processes->shouldReceive('pause')->once();
        $processes->shouldNotReceive('up');
        $processes->shouldNotReceive('resume');
        try {
            app(LiveBackupRestore::class)->run($this->data, $backup, $processes);
            $this->fail('Import failure ignored');
        } catch (\RuntimeException) {
            $journal = app(LiveBackupRestore::class)->read($this->id);
            $this->assertSame('failed', $journal['state']);
            $this->assertTrue($journal['maintenance']);
            $this->assertSame('before.zip', $journal['before_backup']);
        }
        $this->assertFileExists(config('backup.restore_hold'));
    }

    public function test_mismatched_app_key_is_rejected_before_maintenance(): void
    {
        $backup = $this->mock(PlatformBackup::class);
        $backup->shouldReceive('verify')->andReturn(['app_key' => 'different']);
        $processes = $this->mock(RecoveryProcesses::class);
        $processes->shouldNotReceive('down');
        $this->expectExceptionMessage('APP_KEY backup berbeda');
        app(LiveBackupRestore::class)->run($this->data, $backup, $processes);
    }

    public function test_active_endpoint_requires_extra_confirmation_and_status_uses_no_database_queries(): void
    {
        Queue::fake();
        $this->partialMock(PlatformBackup::class, fn ($mock) => $mock->shouldReceive('configured')->andReturn(true));
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $name = 'digitaltoko-20261103-100000-abcd1234.zip';
        file_put_contents(app(BackupRecovery::class)->root().'/'.$name, 'queued fixture');
        $this->post('/admin/backups/restore', ['mode' => 'active', 'snapshot' => $name, 'confirmation' => 'PULIHKAN'])->assertSessionHasErrors('active_confirmation');
        $this->post('/admin/backups/restore', ['mode' => 'active', 'snapshot' => $name, 'confirmation' => 'PULIHKAN', 'active_confirmation' => 'TIMPA DATA AKTIF'])->assertRedirect();
        auth()->logout();
        $url = URL::temporarySignedRoute('recovery.status', now()->addHour(), ['id' => $this->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($url)->assertOk()->assertJsonPath('state', 'queued')->assertJsonMissingPath('database');
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        $this->get('/recovery-status/'.$this->id)->assertForbidden();
    }

    public function test_resuming_requires_admin_review_and_remains_blocked_in_preview(): void
    {
        file_put_contents(config('backup.restore_hold'), json_encode(['id' => $this->id]));
        $this->post('/admin/backups/resume')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => 'owner']))->post('/admin/backups/resume')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->post('/admin/backups/resume', ['id' => $this->id])->assertSessionHasErrors('reviewed');
        $this->mock(LiveBackupRestore::class)->shouldReceive('resume')->once()->withArgs(fn ($id, $processes) => $id === $this->id && $processes instanceof RecoveryProcesses);
        $this->post('/admin/backups/resume', ['id' => $this->id, 'reviewed' => true])->assertRedirect('/admin/backups');
        config(['backup.preview' => true]);
        $this->postJson('/admin/backups/resume', ['id' => $this->id, 'reviewed' => true])->assertStatus(423);
    }

    public function test_inertia_actions_during_review_redirect_to_a_readable_notice_without_running_action(): void
    {
        file_put_contents(config('backup.restore_hold'), json_encode(['id' => $this->id]));
        $owner = User::factory()->create();
        $name = $owner->name;
        $this->actingAs($owner)->withHeaders(['X-Inertia' => 'true', 'Accept' => 'text/html, application/xhtml+xml'])
            ->patch('/owner/account', ['name' => 'Must not change'])->assertStatus(409)->assertHeader('X-Inertia-Location', '/recovery-review');
        $this->assertSame($name, $owner->fresh()->name);
        $this->get('/recovery-review')->assertOk()->assertSee('Buka kembali layanan')->assertSee('OTP')->assertDontSee($this->id);
        $this->assertFileExists(config('backup.restore_hold'));
    }

    public function test_review_notice_works_without_database_queries_and_normal_forms_redirect(): void
    {
        file_put_contents(config('backup.restore_hold'), json_encode(['id' => $this->id]));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->get('/recovery-review')->assertOk()->assertSee('Hasil pemulihan menunggu tinjauan admin')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        $this->post('/login/otp')->assertRedirect('/recovery-review');
        $this->postJson('/login/otp')->assertStatus(423)->assertJsonPath('message', 'Hasil pemulihan menunggu tinjauan admin. Buka Backup & pemulihan, tinjau transaksi dan pembayaran, lalu pilih Buka kembali layanan.');
    }

    public function test_preview_inertia_actions_and_restore_lease_use_notice_while_api_keeps_error_status(): void
    {
        config(['backup.preview' => true]);
        $this->withHeaders(['X-Inertia' => 'true'])->post('/account/otp')->assertStatus(409)->assertHeader('X-Inertia-Location', '/recovery-review');
        $this->get('/recovery-review')->assertOk()->assertSee('Pratinjau pemulihan hanya baca');
        $this->flushHeaders();
        $this->postJson('/api/commerce/digiflazz')->assertStatus(423);
        config(['backup.preview' => false]);
        $lock = fopen(config('backup.restore_lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $this->withHeaders(['X-Inertia' => 'true'])->get('/owner')->assertStatus(409)->assertHeader('X-Inertia-Location', '/recovery-review');
            $this->get('/recovery-review')->assertOk()->assertSee('Pemulihan sedang berlangsung');
            $this->flushHeaders();
            $this->getJson('/api/commerce/digiflazz')->assertStatus(503);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
