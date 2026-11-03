<?php

namespace App\Jobs;

use App\Services\BackupRequests;
use App\Services\PlatformBackup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class RunPlatformBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(public string $requestId)
    {
        $this->onConnection('backups')->onQueue('backups');
    }

    public function handle(BackupRequests $requests, PlatformBackup $backup): void
    {
        if (! $requests->start($this->requestId)) {
            return;
        }
        try {
            $backup->create();
            $requests->finish($this->requestId, true);
        } catch (\Throwable $error) {
            report($error);
            $backup->failure();
            $requests->finish($this->requestId, false);
            throw new RuntimeException('Backup gagal. Periksa binary MySQL, ruang/izin penyimpanan, atau perubahan media selama snapshot.');
        }
    }

    public function failed(?\Throwable $exception): void
    {
        try {
            if (app(BackupRequests::class)->finish($this->requestId, false)) {
                app(PlatformBackup::class)->failure();
            }
        } catch (\Throwable) {
            // Worker logs remain available when request-state storage is unavailable.
        }
    }
}
