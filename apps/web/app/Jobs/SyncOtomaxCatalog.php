<?php

namespace App\Jobs;

use App\Models\OtomaxImport;
use App\Services\OtomaxCatalogSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SyncOtomaxCatalog implements ShouldQueue
{
    use Queueable;

    public int $tries = 30;

    public int $timeout = 180;

    public function __construct(public string $importId) {}

    public function handle(OtomaxCatalogSync $sync): void
    {
        $record = OtomaxImport::find($this->importId);
        if (! $record || $record->source !== 'url' || ! in_array($record->status, ['queued', 'processing'], true)) {
            return;
        }
        $lock = Cache::lock('otomax-import-connection:'.$record->connection_id, 210);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $sync->run($record->fresh());
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        if ($record = OtomaxImport::find($this->importId)) {
            app(OtomaxCatalogSync::class)->fail($record, 'Antrean sinkronisasi melewati batas waktu. Periksa worker lalu coba kembali.');
        }
    }
}
