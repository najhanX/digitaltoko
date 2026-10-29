<?php

namespace App\Services;

use App\Jobs\SyncOtomaxCatalog;
use App\Models\OtomaxImport;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Support\CatalogAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class OtomaxCatalogSync
{
    public function fingerprint(SupplierConnection $connection): string
    {
        return app(OtomaxImports::class)->fingerprint($connection);
    }

    public function summary(SupplierConnection $connection, array $rows): array
    {
        $existing = SupplierProduct::where('store_id', $connection->store_id)->where('connection_id', $connection->id)->where('mode', 'prepaid')->get()->keyBy('sku');
        $included = array_column($rows, 'sku');
        $available = $existing->where('available', true);
        $missing = $available->keys()->diff($included)->count();

        return ['valid' => count($rows), 'new' => count(array_diff($included, $existing->keys()->all())),
            'price_changed' => collect($rows)->filter(fn ($row) => isset($existing[$row['sku']]) && ($existing[$row['sku']]->cost !== $row['cost'] || ($existing[$row['sku']]->billing['adjustment'] ?? null) !== ((is_string($row['billing'] ?? null) ? json_decode($row['billing'], true) : ($row['billing'] ?? []))['adjustment'] ?? null)))->count(),
            'unavailable' => collect($rows)->where('available', false)->count(), 'missing' => $missing,
            'needs_confirmation' => ($available->isNotEmpty() && count($rows) === 0) || ($available->count() >= 20 && $missing > $available->count() / 2)];
    }

    public function preview(SupplierConnection $connection, array $config, string $actor): OtomaxImport
    {
        abort_if($connection->store->sandbox, 422, 'Nonaktifkan mode pengujian untuk menguji URL supplier nyata. Mode pengujian tidak mengakses supplier.');
        $binding = $this->metadata($connection);
        $snapshot = app(OtomaxWebCatalog::class)->fetch($config);
        $record = $this->record($connection, 'draft', [...$binding, 'config' => $config, 'actor' => $actor, 'observed_at' => $snapshot['observed_at']]);
        $summary = $this->summary($connection, $snapshot['rows']);
        app(OtomaxImports::class)->savePreview($record, $record->payload, $summary, $snapshot['rows']);

        return $record->fresh();
    }

    public function activate(SupplierConnection $connection, OtomaxImport $preview, string $actor, bool $acceptMissing): void
    {
        DB::transaction(function () use ($connection, $preview, $actor, $acceptMissing) {
            $store = Store::whereKey($connection->store_id)->lockForUpdate()->firstOrFail();
            $this->guard($store);
            $locked = SupplierConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $record = OtomaxImport::whereKey($preview->id)->lockForUpdate()->firstOrFail();
            $payload = $record->payload;
            abort_unless($locked->enabled && $record->store_id === $store->id && $record->connection_id === $locked->id && $record->source === 'url' && $record->status === 'preview' && $record->expires_at->isFuture() && ($payload['actor'] ?? '') === $actor, 422, 'Pratinjau tidak berlaku. Uji URL kembali.');
            $this->assertCurrent($locked, $payload);
            abort_if($locked->catalog_status === 'queued' || $locked->catalog_status === 'processing', 422, 'Tunggu sinkronisasi berjalan selesai.');
            abort_if(($record->summary['needs_confirmation'] ?? false) && ! $acceptMissing, 422, 'Jumlah produk berkurang lebih dari separuh. Periksa dan setujui produk yang hilang.');
            $locked->update(['catalog_config' => $payload['config'], 'catalog_revision' => $locked->catalog_revision + 1, 'catalog_error' => null, 'catalog_status' => 'queued', 'catalog_next_sync_at' => now()->addMinutes($payload['config']['interval'])]);
            $record->update(['status' => 'queued', 'expires_at' => now()->addDay(), 'payload' => [...$payload, 'revision' => $locked->catalog_revision, 'accept_missing' => $acceptMissing]]);
            DB::afterCommit(fn () => $this->dispatch($record));
        });
    }

    public function enqueue(SupplierConnection $connection, bool $scheduled = false): ?OtomaxImport
    {
        return DB::transaction(function () use ($connection, $scheduled) {
            $store = Store::whereKey($connection->store_id)->lockForUpdate()->firstOrFail();
            $this->guard($store);
            $locked = SupplierConnection::with('store')->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->enabled && $locked->catalog_config, 422, 'Uji dan simpan URL daftar harga terlebih dahulu.');
            abort_if($store->sandbox, 422, 'Sinkronisasi URL supplier tidak dijalankan dalam mode pengujian.');
            if (in_array($locked->catalog_status, ['queued', 'processing'], true)) {
                return null;
            }
            if ($scheduled && (! $locked->catalog_config['automatic'] || $locked->catalog_next_sync_at?->isFuture())) {
                return null;
            }
            if ($locked->catalog_next_sync_at?->isFuture() && ! $scheduled && $locked->catalog_last_checked_at?->gt(now()->subMinutes(5))) {
                throw ValidationException::withMessages(['catalog' => 'Tunggu lima menit sejak pemeriksaan terakhir sebelum sinkronisasi ulang.']);
            }
            if ($locked->catalog_status === 'failed' && $locked->catalog_next_sync_at?->isFuture() && ! $scheduled) {
                throw ValidationException::withMessages(['catalog' => 'Sinkronisasi sedang dalam masa tunggu setelah kegagalan. Periksa jadwal percobaan berikutnya.']);
            }
            $record = $this->record($locked, 'queued', $this->metadata($locked));
            $locked->update(['catalog_status' => 'queued', 'catalog_next_sync_at' => now()->addMinutes($locked->catalog_config['interval'])]);
            DB::afterCommit(fn () => $this->dispatch($record));

            return $record;
        });
    }

    private function metadata(SupplierConnection $connection): array
    {
        return ['config' => $connection->catalog_config, 'revision' => $connection->catalog_revision, 'binding' => $this->fingerprint($connection),
            'sandbox' => (bool) $connection->store->sandbox, 'catalog_synced_at' => $connection->synced_at?->toISOString()];
    }

    private function record(SupplierConnection $connection, string $status, array $payload): OtomaxImport
    {
        return OtomaxImport::create(['store_id' => $connection->store_id, 'connection_id' => $connection->id, 'source' => 'url', 'status' => $status,
            'filename' => $connection->name ?: 'Supplier OtomaX', 'file_hash' => hash_hmac('sha256', json_encode($payload), config('app.key')), 'summary' => [], 'payload' => $payload, 'expires_at' => now()->addMinutes(15)]);
    }

    private function dispatch(OtomaxImport $record): void
    {
        try {
            SyncOtomaxCatalog::dispatch($record->id);
        } catch (\Throwable) {
            $this->fail($record, 'Antrean sinkronisasi tidak tersedia. Periksa queue worker server.');
        }
    }

    private function guard(Store $store): void
    {
        StoreRetention::guard($store);
        CatalogAccess::operational($store);
        abort_unless(CatalogAccess::supports($store, 'otomax'), 403);
    }

    private function assertCurrent(SupplierConnection $connection, array $payload): void
    {
        abort_unless($connection->kind === 'otomax' && $connection->enabled && ! $connection->store->sandbox && ($payload['sandbox'] ?? true) === false
            && $connection->catalog_revision === ($payload['revision'] ?? -1) && hash_equals($this->fingerprint($connection), $payload['binding'] ?? '')
            && $connection->synced_at?->toISOString() === ($payload['catalog_synced_at'] ?? null), 409, 'Koneksi, mode atau katalog berubah. Uji URL kembali.');
    }

    public function run(OtomaxImport $record): void
    {
        if ($record->source !== 'url' || ! in_array($record->status, ['queued', 'processing'], true)) {
            return;
        }
        try {
            $connection = SupplierConnection::with('store')->findOrFail($record->connection_id);
            $payload = $record->payload;
            $this->guard($connection->store);
            $this->assertCurrent($connection, $payload);
            abort_if($record->expires_at->isPast(), 422, 'Antrean sinkronisasi kedaluwarsa.');
            $record->update(['status' => 'processing']);
            $connection->update(['catalog_status' => 'processing']);
            $snapshot = isset($payload['rows_path'])
                ? ['rows' => app(OtomaxImports::class)->previewRows($record, $payload), 'observed_at' => $payload['observed_at']]
                : app(OtomaxWebCatalog::class)->fetch($payload['config']);
            $summary = $this->summary($connection, $snapshot['rows']);
            abort_if($summary['needs_confirmation'] && empty($payload['accept_missing']), 422, 'Daftar harga berkurang lebih dari separuh. Uji URL dan periksa produk yang hilang sebelum melanjutkan.');
            DB::transaction(function () use ($record, $payload, $snapshot, $summary) {
                $store = Store::whereKey($record->store_id)->lockForUpdate()->firstOrFail();
                $this->guard($store);
                $connection = SupplierConnection::with('store')->whereKey($record->connection_id)->lockForUpdate()->firstOrFail();
                $this->assertCurrent($connection, $payload);
                $observed = Carbon::parse($snapshot['observed_at']);
                abort_if($connection->catalog_last_checked_at?->gt($observed), 409, 'Snapshot lebih lama daripada katalog yang sudah diterapkan.');
                $existing = SupplierProduct::where('store_id', $store->id)->where('connection_id', $connection->id)->where('mode', 'prepaid')->get()->keyBy('sku');
                $values = [];
                foreach ($snapshot['rows'] as $row) {
                    $old = $existing[$row['sku']] ?? null;
                    $newerPrice = empty($row['billing']) && $old && ($row['cost'] <= 0 || ($old->price_observed_at && $old->price_observed_at->gte($observed)));
                    $values[] = [...$row, 'billing' => isset($row['billing']) ? json_encode($row['billing']) : null, 'id' => $old?->id ?? (string) Str::uuid7(), 'store_id' => $store->id, 'connection_id' => $connection->id,
                        'cost' => $newerPrice ? $old->cost : $row['cost'], 'price_observed_at' => $newerPrice ? $old->price_observed_at : $observed,
                        'observed_at' => $observed, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()];
                }
                $summary['price_changed'] = collect($values)->filter(fn ($row) => isset($existing[$row['sku']]) && ($existing[$row['sku']]->cost !== $row['cost'] || ($existing[$row['sku']]->billing['adjustment'] ?? null) !== ((is_string($row['billing'] ?? null) ? json_decode($row['billing'], true) : ($row['billing'] ?? []))['adjustment'] ?? null)))->count();
                foreach (array_chunk($values, 100) as $chunk) {
                    SupplierProduct::upsert($chunk, ['connection_id', 'mode', 'sku'], ['name', 'kind', 'billing', 'service', 'category', 'cost', 'available', 'quantity', 'availability_reason', 'observed_at', 'price_observed_at', 'updated_at']);
                }
                $included = array_fill_keys(array_column($snapshot['rows'], 'sku'), true);
                foreach ($existing->chunk(150) as $chunk) {
                    $missing = $chunk->filter(fn ($row) => ! isset($included[$row->sku]))->pluck('id');
                    SupplierProduct::whereIn('id', $missing)->update(['available' => false, 'availability_reason' => 'Tidak ditemukan pada daftar harga supplier.', 'observed_at' => $observed, 'updated_at' => now()]);
                }
                $connection->update(['synced_at' => $observed, 'catalog_last_checked_at' => $observed, 'catalog_status' => 'completed', 'catalog_error' => null, 'last_error' => null, 'catalog_next_sync_at' => now()->addMinutes($payload['config']['interval'])->addSeconds(random_int(0, 30))]);
                $record->update(['status' => 'completed', 'processed' => count($values), 'summary' => $summary, 'error' => null, 'payload' => array_intersect_key($payload, array_flip(['rows_path', 'rows_hash']))]);
            }, 3);
            app(StoreCatalog::class)->reprice($connection->store);
            if (isset($payload['rows_path'])) {
                $record->payload = $payload;
                app(OtomaxImports::class)->deleteFiles($record);
                $record->update(['payload' => []]);
            }
        } catch (\Throwable $error) {
            $message = $error instanceof ValidationException ? (string) collect($error->errors())->flatten()->first() : ($error instanceof HttpExceptionInterface ? $error->getMessage() : 'Sinkronisasi terhenti. Periksa koneksi, Redis, dan konfigurasi server.');
            $this->fail($record, $message);
        }
    }

    public function fail(OtomaxImport $record, string $message): void
    {
        if ($record->fresh()?->status === 'completed') {
            return;
        }
        $message = Str::limit($message, 250, '');
        $record->update(['status' => 'failed', 'error' => $message]);
        $connection = SupplierConnection::find($record->connection_id);
        if ($connection && $connection->catalog_revision === ($record->payload['revision'] ?? -1)) {
            $connection->update(['catalog_status' => 'failed', 'catalog_error' => $message, 'catalog_next_sync_at' => now()->addMinutes(5)]);
        }
    }
}
