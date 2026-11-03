<?php

namespace Tests\Feature;

use App\Services\PlatformBackup;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class PlatformBackupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/backup-test-'.Str::uuid());
        File::makeDirectory($this->root, 0700, true);
        config(['backup.key' => 'base64:'.base64_encode(random_bytes(32)), 'backup.directory' => $this->root, 'backup.restore_limit_bytes' => 100000]);
    }

    protected function tearDown(): void
    {
        $path = realpath($this->root);
        if ($path && dirname($path) === realpath(storage_path('framework/testing')) && str_starts_with(basename($path), 'backup-test-')) {
            File::deleteDirectory($path);
        }parent::tearDown();
    }

    private function archive(array $files, bool $encrypt = true): string
    {
        $name = $this->root.'/'.Str::uuid().'.zip';
        $zip = new ZipArchive;
        $zip->open($name, ZipArchive::CREATE);
        $zip->setPassword(substr(config('backup.key'), 7));
        $manifest = ['version' => 1, 'app_key' => 'base64:test-key', 'files' => []];
        foreach ($files as $path => $data) {
            $zip->addFromString($path, $data);
            $manifest['files'][$path] = ['size' => strlen($data), 'sha256' => hash('sha256', $data)];
            if ($encrypt) {
                $zip->setEncryptionName($path, ZipArchive::EM_AES_256);
            }
        }
        $zip->addFromString('manifest.json', json_encode($manifest));
        if ($encrypt) {
            $zip->setEncryptionName('manifest.json', ZipArchive::EM_AES_256);
        }$zip->close();

        return $name;
    }

    public function test_verifies_encrypted_backup_and_cli_does_not_print_key(): void
    {
        $path = $this->archive(['database.sql' => 'SELECT 1;', 'files/private/test.txt' => 'secret test value']);
        $this->assertSame(2, count(app(PlatformBackup::class)->verify($path)['files']));
        $this->artisan('digitaltoko:backup-verify', ['file' => $path])->expectsOutput('Backup berhasil diverifikasi; database tidak diubah.')->assertSuccessful();
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertFalse(@$zip->getFromName('files/private/test.txt'));
        $zip->close();
    }

    public function test_rejects_wrong_key_plaintext_and_path_traversal(): void
    {
        foreach ([['database.sql' => 'test', 'files/private/../../escape' => 'bad'], ['database.sql' => 'test']] as $i => $files) {
            $path = $this->archive($files, $i === 0);
            try {
                app(PlatformBackup::class)->verify($path);
                $this->fail('Unsafe archive accepted');
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
        $path = $this->archive(['database.sql' => 'test']);
        config(['backup.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->artisan('digitaltoko:backup-verify', ['file' => $path])->assertFailed();
    }

    public function test_rejects_tampered_contents_size_limits_and_existing_restore_target(): void
    {
        $path = $this->archive(['database.sql' => 'test']);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->setPassword(substr(config('backup.key'), 7));
        $zip->addFromString('database.sql', 'tampered');
        $zip->setEncryptionName('database.sql', ZipArchive::EM_AES_256);
        $zip->close();
        $this->artisan('digitaltoko:backup-verify', ['file' => $path])->assertFailed();
        $path = $this->archive(['database.sql' => str_repeat('x', 100)]);
        config(['backup.restore_limit_bytes' => 50]);
        $this->artisan('digitaltoko:backup-verify', ['file' => $path])->assertFailed();
        $this->artisan('digitaltoko:backup-restore', ['file' => $path, '--database' => 'digitaltoko', '--directory' => $this->root])->assertFailed();
    }

    public function test_health_reports_failed_missing_and_stale_backup_without_secret_details(): void
    {
        $backup = app(PlatformBackup::class);
        $this->assertSame('warning', $backup->health()['status']);
        $file = $this->archive(['database.sql' => 'fixture']);
        file_put_contents($this->root.'/status.json', json_encode(['status' => 'ok', 'last_success_at' => now()->toIso8601String(), 'file' => basename($file)]));
        $this->assertSame('ok', $backup->health()['status']);
        $this->travel(31)->hours();
        $this->assertSame('warning', $backup->health()['status']);

        file_put_contents($this->root.'/status.json', json_encode(['status' => 'ok', 'last_success_at' => 'invalid-date', 'file' => []]));
        $this->assertSame('warning', $backup->health()['status']);
        $this->assertNull($backup->health()['checked_at']);
        $backup->failure();
        $this->assertSame('error', $backup->health()['status']);
        $this->assertStringNotContainsString(config('backup.key'), json_encode($backup->health()));
    }

    public function test_failed_export_preserves_old_archives_and_cleans_plaintext_work_files(): void
    {
        $old = $this->archive(['database.sql' => 'previous']);
        config(['backup.sources' => []]);
        $this->artisan('digitaltoko:backup')->assertFailed();
        $this->assertFileExists($old);
        $this->assertSame([], glob($this->root.'/.work-*'));
        $this->assertSame([], glob($this->root.'/*.partial'));
        $this->assertSame('error', app(PlatformBackup::class)->health()['status']);
    }
}
