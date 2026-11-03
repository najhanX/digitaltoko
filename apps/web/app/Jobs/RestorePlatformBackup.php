<?php

namespace App\Jobs;

use App\Services\BackupRecovery;
use App\Services\LiveBackupRestore;
use App\Services\PlatformBackup;
use App\Services\RecoveryProcesses;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RestorePlatformBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public string $requestId)
    {
        $this->onConnection('backups')->onQueue('backups');
    }

    public function handle(BackupRecovery $requests, PlatformBackup $backup): void
    {
        $data = $requests->start($this->requestId);
        if (! $data) {
            return;
        }
        try {
            $input = $requests->input($this->requestId);
            if (is_link($input) || ! is_file($input)) {
                throw new RuntimeException('Arsip pemulihan tidak tersedia.');
            }
            if (($data['mode'] ?? 'isolated') === 'active') {
                app(LiveBackupRestore::class)->run($data, $backup, app(RecoveryProcesses::class));
            } else {
                $backup->restore($input, $data['database'], $requests->destination($this->requestId));
            }
            $requests->finish($this->requestId, true);
        } catch (\Throwable $error) {
            Log::error('Pemulihan backup gagal.', ['restore_id' => $this->requestId, 'exception' => $error]);
            if (($data['mode'] ?? 'isolated') === 'active') {
                $restore = app(LiveBackupRestore::class);
                $journal = $restore->read($this->requestId);
                if (! in_array($journal['state'] ?? null, ['completed', 'failed'], true)) {
                    $restore->record($this->requestId, ['state' => 'failed', 'phase' => str_contains($error->getMessage(), 'APP_KEY') ? 'APP_KEY backup berbeda. Gunakan database terpisah atau prosedur pergantian kunci operator.' : 'Restore belum berhasil. Periksa PM2, integritas arsip, kapasitas disk dan log worker.']);
                }
            }
            try {
                $requests->finish($this->requestId, false);
            } catch (\Throwable $auditError) {
                Log::error('Riwayat database pemulihan belum dapat ditulis; gunakan jurnal filesystem.', ['restore_id' => $this->requestId, 'exception' => $auditError]);
            }
            throw new RuntimeException('Pemulihan backup gagal. Lihat riwayat pemulihan dan log operator.');
        } finally {
            $requests->cleanInput($this->requestId);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        try {
            app(BackupRecovery::class)->finish($this->requestId, false);
        } catch (\Throwable $error) {
            Log::error('Status pemulihan tersimpan pada jurnal filesystem.', ['restore_id' => $this->requestId, 'exception' => $error]);
        } finally {
            app(BackupRecovery::class)->cleanInput($this->requestId);
        }
    }
}
