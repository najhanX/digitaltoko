<?php

namespace App\Console\Commands;

use App\Models\SupplierConnection;
use App\Services\SupplierCatalog;
use App\Support\CatalogAccess;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('digitaltoko:catalog-sync {--store=}')]
#[Description('Perbarui snapshot pemasok toko aktif dengan cache Redis per akun.')]
class SyncStoreCatalogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SupplierCatalog $catalog): int
    {
        $query = SupplierConnection::with('store.owner')->where('enabled', true)->whereHas('store', fn ($q) => $q->whereIn('status', ['active', 'grace', 'provisioning']));
        if ($this->option('store')) {
            $query->where('store_id', $this->option('store'));
        }
        $failed = 0;
        $completed = 0;
        $query->chunkById(50, function ($connections) use ($catalog, &$failed, &$completed) {
            foreach ($connections as $connection) {
                if (! CatalogAccess::supports($connection->store, $connection->kind) || (! $connection->store->acceptsOrders() && $connection->store->status !== 'provisioning')) {
                    continue;
                }
                foreach ($connection->catalog_modes ?? ['prepaid'] as $mode) {
                    try {
                        $catalog->sync($connection, $mode);
                        $completed++;
                    } catch (\Throwable $error) {
                        $connection->update(['last_error' => 'Sinkronisasi otomatis gagal; snapshot sebelumnya dipertahankan.']);
                        $failed++;
                    }
                }
            }
        });
        $this->info($completed.' snapshot diperbarui; '.$failed.' perlu diperiksa.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
