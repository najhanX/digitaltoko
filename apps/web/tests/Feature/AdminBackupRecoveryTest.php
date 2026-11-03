<?php

namespace Tests\Feature;

use App\Jobs\RestorePlatformBackup;
use App\Models\User;
use App\Services\BackupRecovery;
use App\Services\PlatformBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AdminBackupRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private string $name = 'digitaltoko-20261103-100000-abcd1234.zip';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->root = storage_path('framework/testing/recovery-test-'.Str::uuid());
        File::ensureDirectoryExists($this->root, 0700);
        config(['platform.domain' => 'localhost', 'app.url' => 'http://localhost', 'inertia.ssr.enabled' => false,
            'backup.directory' => $this->root, 'backup.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Queue::fake([RestorePlatformBackup::class]);
        $this->partialMock(PlatformBackup::class, fn ($mock) => $mock->shouldReceive('configured')->andReturn(true));
        $zip = new ZipArchive;
        $zip->open($this->root.'/'.$this->name, ZipArchive::CREATE);
        $zip->setPassword(substr(config('backup.key'), 7));
        $sql = 'SELECT 1;';
        $zip->addFromString('database.sql', $sql);
        $zip->addFromString('manifest.json', json_encode(['version' => 1, 'app_key' => config('app.key'), 'files' => ['database.sql' => ['size' => strlen($sql), 'sha256' => hash('sha256', $sql)]]]));
        $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256);
        $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
        $zip->close();
    }

    protected function tearDown(): void
    {
        $path = realpath($this->root);
        if ($path && dirname($path) === realpath(storage_path('framework/testing')) && str_starts_with(basename($path), 'recovery-test-')) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    private function admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function job(): RestorePlatformBackup
    {
        $job = null;
        Queue::assertPushed(RestorePlatformBackup::class, function ($queued) use (&$job) {
            $job = $queued;

            return true;
        });

        return $job;
    }

    public function test_history_download_and_restore_are_admin_only(): void
    {
        foreach (['/admin/backups', '/admin/backups/download/'.$this->name] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
            $this->actingAs(User::factory()->create(['role' => 'owner']))->get($url)->assertForbidden();
            auth()->logout();
        }
        $this->post('/admin/backups/restore')->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create(['role' => 'owner']))->post('/admin/backups/restore')->assertForbidden();
        $this->admin();
        $response = $this->get('/admin/backups/download/'.$this->name)->assertOk()->assertDownload($this->name);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/admin/backups/download/status.json')->assertNotFound();
        $this->get('/admin/backups/download/digitaltoko-20261103-100000-zzzz9999.zip')->assertNotFound();
    }

    public function test_snapshot_listing_searches_and_paginates_without_exposing_secrets(): void
    {
        $this->admin();
        for ($i = 0; $i < 11; $i++) {
            copy($this->root.'/'.$this->name, $this->root.'/digitaltoko-20261102-100000-'.sprintf('%08d', $i).'.zip');
        }
        file_put_contents($this->root.'/not-a-backup.zip', 'not managed');
        $this->get('/admin/backups?per_page=5&page=2')->assertInertia(fn ($page) => $page->component('Admin/Backups')->has('items', 5)->where('pagination.total', 12)->where('pagination.current_page', 2));
        $response = $this->get('/admin/backups?q=abcd1234')->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.name', $this->name));
        $response->assertDontSee(config('backup.key'))->assertDontSee(config('app.key'));
        $this->get('/admin/backups?page[]=bad')->assertOk();
    }

    public function test_history_restore_pins_archive_and_job_runs_once_into_generated_target(): void
    {
        $this->admin();
        $this->post('/admin/backups/restore', ['snapshot' => $this->name, 'confirmation' => 'PULIHKAN'])->assertRedirect('/admin/backups?tab=restores');
        $job = $this->job();
        $this->assertSame('backups', $job->connection);
        $this->post('/admin/backups/restore', ['snapshot' => $this->name, 'confirmation' => 'PULIHKAN'])->assertSessionHasErrors('backup');
        Queue::assertPushed(RestorePlatformBackup::class, 1);
        $service = app(BackupRecovery::class);
        $stage = $service->input($job->requestId);
        $this->assertFileExists($stage);
        unlink($this->root.'/'.$this->name);
        $activeDatabase = DB::connection()->getDatabaseName();
        $this->partialMock(PlatformBackup::class, function ($mock) use ($job, $stage, $activeDatabase) {
            $mock->shouldReceive('configured')->andReturn(true);
            $mock->shouldReceive('restore')->once()->withArgs(function ($archive, $database, $directory) use ($job, $stage, $activeDatabase) {
                $this->assertSame($stage, $archive);
                $this->assertMatchesRegularExpression('/^dt_restore_[a-f0-9]{32}$/D', $database);
                $this->assertNotSame($activeDatabase, $database);
                $this->assertSame(realpath($this->root).'/recoveries/'.$job->requestId, $directory);
                $this->assertCount(1, (new PlatformBackup)->verify($archive)['files']);

                return true;
            });
        });
        $users = DB::table('users')->count();
        $job->handle($service, app(PlatformBackup::class));
        $job->handle($service, app(PlatformBackup::class));
        $job->failed(new \RuntimeException('Late error'));
        $this->assertSame($users, DB::table('users')->count());
        $this->assertFileDoesNotExist($stage);
        $this->get('/admin/backups?tab=restores')->assertInertia(fn ($page) => $page->has('items', 1)->where('items.0.state', 'completed')->where('restoreBusy', false));
    }

    public function test_upload_and_bad_archive_fail_safely_then_allow_new_request(): void
    {
        $this->admin();
        $file = UploadedFile::fake()->createWithContent('original.zip', file_get_contents($this->root.'/'.$this->name));
        $this->post('/admin/backups/restore', ['file' => $file, 'confirmation' => 'PULIHKAN'])->assertSessionHasNoErrors();
        $job = $this->job();
        $stage = app(BackupRecovery::class)->input($job->requestId);
        $this->assertCount(1, (new PlatformBackup)->verify($stage)['files']);
        config(['backup.key' => 'base64:'.base64_encode(random_bytes(32))]);
        try {
            $job->handle(app(BackupRecovery::class), new PlatformBackup);
            $this->fail('Wrong key accepted');
        } catch (\RuntimeException $error) {
            $this->assertSame('Pemulihan backup gagal. Lihat riwayat pemulihan dan log operator.', $error->getMessage());
        }
        $this->assertFileDoesNotExist($stage);
        $this->get('/admin/backups?tab=restores')->assertInertia(fn ($page) => $page->where('items.0.state', 'failed')->where('restoreBusy', false));
        $this->post('/admin/backups/restore', ['snapshot' => $this->name, 'confirmation' => 'PULIHKAN'])->assertSessionHasNoErrors();
    }

    public function test_confirmation_file_limits_and_source_validation(): void
    {
        $this->admin();
        $this->post('/admin/backups/restore', ['snapshot' => $this->name])->assertSessionHasErrors('confirmation');
        $this->post('/admin/backups/restore', ['confirmation' => 'PULIHKAN'])->assertSessionHasErrors(['file', 'snapshot']);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->post('/admin/backups/restore', ['file' => UploadedFile::fake()->create('not-a-backup.txt'), 'confirmation' => 'PULIHKAN'])->assertSessionHasErrors('file');
        config(['backup.upload_max_mb' => 1]);
        $this->post('/admin/backups/restore', ['file' => UploadedFile::fake()->create('large.zip', 2048), 'confirmation' => 'PULIHKAN'])->assertSessionHasErrors('file');
        $this->post('/admin/backups/restore', ['snapshot' => '../status.json', 'confirmation' => 'PULIHKAN'])->assertNotFound();
        $this->post('/admin/backups/restore', ['snapshot' => $this->name, 'file' => UploadedFile::fake()->create('both.zip'), 'confirmation' => 'PULIHKAN'])->assertSessionHasErrors('snapshot');
        Queue::assertNothingPushed();
        $this->assertSame([], glob($this->root.'/.restore-input-*'));
    }

    public function test_expired_requests_never_restore_and_unconfigured_requests_are_rejected(): void
    {
        $this->admin();
        $this->post('/admin/backups/restore', ['snapshot' => $this->name, 'confirmation' => 'PULIHKAN']);
        $job = $this->job();
        $this->travel(16)->minutes();
        $job->handle(app(BackupRecovery::class), app(PlatformBackup::class));
        $this->assertFileDoesNotExist(app(BackupRecovery::class)->input($job->requestId));
        $this->assertFalse(app(BackupRecovery::class)->busy());
        $this->partialMock(PlatformBackup::class, fn ($mock) => $mock->shouldReceive('configured')->andReturn(false));
        $this->post('/admin/backups/restore', ['snapshot' => $this->name, 'confirmation' => 'PULIHKAN'])->assertSessionHasErrors('backup');
    }
}
