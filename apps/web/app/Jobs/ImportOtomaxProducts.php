<?php

namespace App\Jobs;

use App\Models\OtomaxImport;
use App\Services\OtomaxImports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class ImportOtomaxProducts implements ShouldQueue
{
    use Queueable;

    public int $tries = 30;

    public int $timeout = 180;

    public function __construct(public string $importId) {}

    public function handle(OtomaxImports $imports): void
    {
        $record = OtomaxImport::find($this->importId);
        if (! $record || ! in_array($record->status, ['queued', 'processing'], true)) {
            return;
        }
        $lock = Cache::lock('otomax-import-connection:'.$record->connection_id, 210);
        if (! $lock->get()) {
            $this->release(10);

            return;
        }
        try {
            $imports->apply($record->fresh());
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        OtomaxImport::whereKey($this->importId)->whereIn('status', ['queued', 'processing'])->update(['status' => 'failed', 'error' => 'Impor melewati batas waktu atau antrean gagal. Buat pratinjau baru; produk yang sudah masuk tidak diduplikasi.']);
    }
}
