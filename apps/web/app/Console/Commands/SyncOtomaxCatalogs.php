<?php

namespace App\Console\Commands;

use App\Models\OtomaxImport;
use App\Models\SupplierConnection;
use App\Services\OtomaxCatalogSync;
use App\Support\CatalogAccess;
use Illuminate\Console\Command;

class SyncOtomaxCatalogs extends Command
{
    protected $signature = 'digitaltoko:otomax-catalogs {--store=}';

    protected $description = 'Antrekan sinkronisasi URL katalog OtomaX yang sudah jatuh tempo.';

    public function handle(OtomaxCatalogSync $sync): int
    {
        OtomaxImport::where('source', 'url')->whereIn('status', ['queued', 'processing'])->where('updated_at', '<', now()->subMinutes(15))->each(fn ($record) => $sync->fail($record, 'Antrean lama tidak selesai. Periksa queue worker; sinkronisasi akan dicoba ulang.'));
        $count = 0;
        SupplierConnection::with('store')->where('kind', 'otomax')->where('enabled', true)->whereNotNull('catalog_config')
            ->when($this->option('store'), fn ($query, $id) => $query->where('store_id', $id))
            ->where(function ($query) {
                $query->whereNull('catalog_next_sync_at')->orWhere('catalog_next_sync_at', '<=', now());
            })
            ->chunkById(50, function ($connections) use ($sync, &$count) {
                foreach ($connections as $connection) {
                    $store = $connection->store;
                    if (! $store || $store->sandbox || $store->admin_suspended_at || ! $connection->catalog_config['automatic'] || ! CatalogAccess::supports($store, 'otomax') || (! $store->acceptsOrders() && $store->status !== 'provisioning')) {
                        continue;
                    }
                    try {
                        if ($sync->enqueue($connection, true)) {
                            $count++;
                        }
                    } catch (\Throwable) {
                        // A concurrent store/connection change is rechecked on the next tick.
                    }
                }
            });
        $this->info($count.' sinkronisasi URL OtomaX diantrekan.');

        return self::SUCCESS;
    }
}
