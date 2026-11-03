<?php

namespace App\Jobs;

use App\Services\BackupRecovery;
use App\Services\PlatformBackup;
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
            $backup->restore($input, $data['database'], $requests->destination($this->requestId));
            $requests->finish($this->requestId, true);
        } catch (\Throwable $error) {
            Log::error('Pemulihan backup gagal.', ['restore_id' => $this->requestId, 'exception' => $error]);
            $requests->finish($this->requestId, false);
            throw new RuntimeException('Pemulihan backup gagal. Lihat riwayat pemulihan dan log operator.');
        } finally {
            $requests->cleanInput($this->requestId);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        app(BackupRecovery::class)->finish($this->requestId, false);
        app(BackupRecovery::class)->cleanInput($this->requestId);
    }
}
