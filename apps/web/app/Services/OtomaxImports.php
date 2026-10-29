<?php

namespace App\Services;

use App\Models\OtomaxImport;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Support\CatalogAccess;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

class OtomaxImports
{
    public static function defaults(): array
    {
        return ['default_available' => false, 'sheet' => '', 'header_row' => 3, 'grouped' => true, 'group_column' => 'A', 'columns' => ['sku' => 'A', 'name' => 'B', 'cost' => 'C', 'status' => 'F', 'category' => '', 'quantity' => '', 'service' => ''], 'service' => 'Produk digital', 'category' => 'Umum', 'thousands' => '.', 'decimal' => ',', 'delimiter' => ';', 'encoding' => 'UTF-8', 'available_values' => 'Open,Aktif,Ready', 'unavailable_values' => 'Gangguan,Close,Nonaktif', 'prefix_group' => true];
    }

    public function read(string $path, string $type, array $config): array
    {
        if ($type === 'csv') {
            $delimiter = $config['delimiter'] ?? ';';
            if (! in_array($delimiter, [',', ';', "\t"], true)) {
                throw ValidationException::withMessages(['file' => 'Pemisah CSV tidak valid.']);
            }
            $h = fopen($path, 'rb');
            $rows = [];
            try {
                while (($row = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
                    if (count($rows) >= 20000 || count($row) > 32) {
                        throw ValidationException::withMessages(['file' => 'Maksimal 20.000 baris dan 32 kolom.']);
                    }$values = [];
                    foreach ($row as $i => $v) {
                        $value = mb_convert_encoding($v ?? '', 'UTF-8', $config['encoding'] ?? 'UTF-8');
                        $values[Coordinate::stringFromColumnIndex($i + 1)] = ltrim($value, "\xEF\xBB\xBF");
                    }$rows[] = $values;
                }
            } finally {
                fclose($h);
            }

            return ['sheets' => ['CSV'], 'rows' => $rows, 'sheet' => 'CSV'];
        }
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'Berkas bukan XLSX yang valid.']);
        }
        $size = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $size += ($zip->statIndex($i)['size'] ?? 0);
                if ($size > 40000000 || $zip->numFiles > 500) {
                    throw ValidationException::withMessages(['file' => 'Ukuran isi workbook melewati batas.']);
                }
            }
        } finally {
            $zip->close();
        }
        $reader = new Xlsx;
        $info = $reader->listWorksheetInfo($path);
        $names = array_column($info, 'worksheetName');
        $sheet = ($config['sheet'] ?? '') ?: ($names[0] ?? '');
        $selected = collect($info)->firstWhere('worksheetName', $sheet);
        if (! $selected || $selected['totalRows'] > 20000 || $selected['totalColumns'] > 32 || $selected['totalRows'] * $selected['totalColumns'] > 200000) {
            throw ValidationException::withMessages(['file' => 'Sheet tidak ditemukan atau melebihi 20.000 baris / 32 kolom / 200.000 sel.']);
        }
        $reader->setLoadSheetsOnly($sheet);
        $reader->setReadEmptyCells(false);
        $reader->setReadDataOnly(true);
        $workbook = $reader->load($path);
        try {
            $rows = $workbook->getSheetByName($sheet)->toArray(null, false, false, true);

            return ['sheets' => $names, 'rows' => array_values($rows), 'sheet' => $sheet];
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    public function normalize(array $rows, array $c): array
    {
        $products = [];
        $errors = [];
        $seen = [];
        $duplicates = [];
        $group = '';
        $groups = 0;
        $statuses = [];
        $header = (int) ($c['header_row'] ?? 1);
        $cols = $c['columns'];
        $open = array_map(fn ($v) => mb_strtolower(trim($v)), explode(',', $c['available_values'] ?? 'Open'));
        $closed = array_map(fn ($v) => mb_strtolower(trim($v)), explode(',', $c['unavailable_values'] ?? 'Gangguan,Close'));
        foreach (array_slice($rows, $header, null, true) as $index => $row) {
            $num = $index + 1;
            $read = fn ($field) => trim((string) ($row[$cols[$field] ?? ''] ?? ''));
            $sku = strtoupper($read('sku'));
            $name = $read('name');
            if (! array_filter($row, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            if (($c['grouped'] ?? false) && $sku !== '' && $name === '' && trim((string) ($row[$cols['cost']] ?? '')) === '') {
                $group = trim((string) ($row[$c['group_column'] ?? 'A'] ?? ''));
                $groups++;

                continue;
            }
            if ($sku === strtoupper(trim((string) ($rows[$header - 1][$cols['sku']] ?? ''))) && $name === trim((string) ($rows[$header - 1][$cols['name']] ?? ''))) {
                continue;
            }
            $error = null;
            if ($sku === '' || $name === '') {
                $error = 'Kode/nama produk kosong atau baris bukan produk.';
            } elseif (isset($seen[$sku])) {
                $error = 'Kode produk duplikat dalam file.';
                $duplicates[$sku] = true;
            }
            $seen[$sku] = true;
            $costRaw = $row[$cols['cost']] ?? null;
            $negative = str_starts_with(trim((string) $costRaw), '-');
            $cost = $this->number($negative ? substr(trim((string) $costRaw), 1) : $costRaw, $c);
            if ($cost !== null && $negative) {
                $cost = -$cost;
            }
            if ($cost === null) {
                $error ??= $costRaw === null || $costRaw === '' ? 'Harga kosong; bukan Rp0.' : 'Harga nol/tidak valid; produk perlu diperiksa.';
            }
            $hasStatusColumn = filled($cols['status'] ?? null);
            $status = $hasStatusColumn ? $read('status') : (($c['default_available'] ?? false) ? 'Tanpa status: tersedia' : 'Tanpa status: nonaktif');
            $key = mb_strtolower($status);
            $statusLabel = mb_substr($status, 0, 120);
            if (isset($statuses[$statusLabel]) || count($statuses) < 50) {
                $statuses[$statusLabel] = ($statuses[$statusLabel] ?? 0) + 1;
            }
            if ($hasStatusColumn && ! in_array($key, [...$open, ...$closed], true)) {
                $error ??= 'Status belum dipetakan: '.$status;
            }
            $quantity = ($cols['quantity'] ?? '') !== '' ? $this->number($row[$cols['quantity']] ?? null, $c) : null;
            if (($cols['quantity'] ?? '') !== '' && ($read('quantity') !== '' && $quantity === null)) {
                $error ??= 'Stok tidak valid.';
            }
            $category = ($cols['category'] ?? '') !== '' ? $read('category') : (($c['grouped'] ?? false) ? $group : ($c['category'] ?? 'Umum'));
            $service = ($cols['service'] ?? '') !== '' ? $read('service') : ($c['service'] ?? 'Produk digital');
            $name = ($c['prefix_group'] ?? false) && $group !== '' ? $group.' · '.$name : $name;
            if (strlen($sku) > 150 || mb_strlen($name) > 150 || mb_strlen($category) > 100 || mb_strlen($service) > 100 || $category === '' || $service === '' || str_starts_with($sku, '=') || str_starts_with($name, '=')) {
                $error ??= 'Kode/nama/kategori/layanan melebihi batas, kosong, atau berupa formula.';
            }
            if ($error) {
                $errors[] = ['row' => $num, 'sku' => mb_substr($sku, 0, 150), 'error' => $error];

                continue;
            }
            $products[$sku] = ['sku' => $sku, 'name' => $name, 'kind' => $cost <= 0 ? 'postpaid' : 'ppob', 'billing' => $cost <= 0 ? ['adjustment' => $cost] : null, 'mode' => 'prepaid', 'service' => $service, 'category' => $category, 'cost' => max(0, $cost), 'available' => ($hasStatusColumn ? in_array($key, $open, true) : (bool) ($c['default_available'] ?? false)) && ($quantity === null || $quantity > 0), 'quantity' => $quantity];
        }
        foreach (array_keys($duplicates) as $sku) {
            unset($products[$sku]);
        }

        return ['rows' => array_values($products), 'errors' => $errors, 'summary' => ['valid' => count($products), 'rejected' => count($errors), 'groups' => $groups, 'duplicates' => count($duplicates), 'statuses' => $statuses]];
    }

    private function number(mixed $raw, array $c): ?int
    {
        if (is_int($raw) || is_float($raw)) {
            return is_finite((float) $raw) && $raw >= 0 && $raw <= 1000000000000 && floor((float) $raw) == (float) $raw ? (int) $raw : null;
        }
        $s = trim((string) $raw);
        $th = $c['thousands'] ?? '';
        $decimal = $c['decimal'] ?? ',';
        if ($s === '' || str_starts_with($s, '=')) {
            return null;
        }
        if ($th !== '' && str_contains($s, $th)) {
            $integer = explode($decimal, $s)[0];
            if (! preg_match('/^\d{1,3}(?:'.preg_quote($th, '/').'\d{3})+$/', $integer)) {
                return null;
            }
            $s = str_replace($th, '', $s);
        }
        if ($decimal !== '' && str_contains($s, $decimal)) {
            [$s,$fraction] = explode($decimal, $s, 2);
            if (! preg_match('/^0+$/', $fraction)) {
                return null;
            }
        }

        return ctype_digit($s) && strlen($s) <= 13 && (float) $s <= 1000000000000 ? (int) $s : null;
    }

    /** Keep large normalized rows out of database packets, including encrypted-cast overhead. */
    public function savePreview(OtomaxImport $import, array $metadata, array $summary, array $rows): void
    {
        $path = 'otomax/'.$import->store_id.'/previews/'.$import->id.'/'.Str::uuid().'.enc';
        $encrypted = Crypt::encryptString(json_encode(['store_id' => $import->store_id, 'import_id' => $import->id, 'rows' => $rows], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $disk = Storage::disk('local');
        if (! $disk->put($path, $encrypted, ['visibility' => 'private'])) {
            throw ValidationException::withMessages(['file' => 'Pratinjau tidak dapat disimpan. Periksa penyimpanan privat server, lalu coba kembali.']);
        }
        $previous = null;
        try {
            DB::transaction(function () use ($import, $metadata, $summary, $path, $encrypted, &$previous) {
                $locked = OtomaxImport::whereKey($import->id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($locked->status, ['draft', 'preview'], true) && $locked->expires_at->isFuture(), 422, 'Pratinjau kedaluwarsa atau sudah dikirim.');
                $previous = $locked->payload;
                unset($metadata['rows'], $metadata['errors']);
                $locked->update(['status' => 'preview', 'summary' => $summary, 'payload' => [...$metadata, 'rows_path' => $path, 'rows_hash' => hash('sha256', $encrypted)]]);
            });
        } catch (\Throwable $error) {
            $disk->delete($path);
            throw $error;
        }
        if ($previous && ($previous['rows_path'] ?? null) !== $path) {
            $this->deletePreviewRows($import, $previous);
        }
    }

    public function previewRows(OtomaxImport $import, array $payload): array
    {
        if (! isset($payload['rows_path'])) {
            return $payload['rows'] ?? throw new \RuntimeException('Data pratinjau tidak tersedia.');
        }
        $path = $this->previewPath($import, $payload);
        $encrypted = Storage::disk('local')->get($path);
        if (! is_string($encrypted) || ! hash_equals((string) ($payload['rows_hash'] ?? ''), hash('sha256', $encrypted))) {
            throw new \RuntimeException('Berkas pratinjau hilang atau berubah.');
        }
        $data = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        if (($data['store_id'] ?? null) !== $import->store_id || ($data['import_id'] ?? null) !== $import->id || ! is_array($data['rows'] ?? null)) {
            throw new \RuntimeException('Berkas pratinjau tidak sesuai toko/impor.');
        }

        return $data['rows'];
    }

    private function previewPath(OtomaxImport $import, array $payload): string
    {
        $path = (string) ($payload['rows_path'] ?? '');
        $prefix = 'otomax/'.$import->store_id.'/previews/'.$import->id.'/';
        if (! str_starts_with($path, $prefix) || ! preg_match('/^[a-f0-9-]{36}\.enc$/D', substr($path, strlen($prefix)))) {
            throw new \RuntimeException('Lokasi pratinjau tidak valid.');
        }

        return $path;
    }

    private function deletePreviewRows(OtomaxImport $import, array $payload): void
    {
        if (isset($payload['rows_path']) && ! Storage::disk('local')->delete($this->previewPath($import, $payload))) {
            throw new \RuntimeException('Berkas pratinjau belum dapat dibersihkan.');
        }
    }

    public function deleteFiles(OtomaxImport $import): void
    {
        $payload = $import->payload;
        $this->deletePreviewRows($import, $payload);
        if (isset($payload['path']) && ! Storage::disk('local')->delete($payload['path'])) {
            throw new \RuntimeException('Berkas unggahan belum dapat dibersihkan.');
        }
    }

    public function apply(OtomaxImport $import): void
    {
        if (! in_array($import->status, ['queued', 'processing'], true)) {
            return;
        }
        try {
            $payload = $import->payload;
            $c = SupplierConnection::with('store')->findOrFail($import->connection_id);
            if ($import->expires_at->isPast() || $this->fingerprint($c) !== ($payload['connection_hash'] ?? '') || $c->store->sandbox !== ($payload['sandbox'] ?? null)) {
                throw new \RuntimeException('Koneksi/mode berubah atau pratinjau kedaluwarsa.');
            }
            $all = $this->previewRows($import, $payload);
            $import->update(['status' => 'processing']);
            while ($import->processed < count($all)) {
                DB::transaction(function () use ($import, $payload, $all) {
                    $store = Store::whereKey($import->store_id)->lockForUpdate()->firstOrFail();
                    StoreRetention::guard($store);
                    CatalogAccess::operational($store);
                    abort_unless(CatalogAccess::supports($store, 'otomax'), 403);
                    $c = SupplierConnection::whereKey($import->connection_id)->lockForUpdate()->firstOrFail();
                    if (! $c->enabled || $this->fingerprint($c) !== $payload['connection_hash'] || $store->sandbox !== $payload['sandbox'] || ($c->synced_at?->toISOString() !== ($payload['catalog_synced_at'] ?? null))) {
                        throw new \RuntimeException('Koneksi berubah atau sudah ada impor lebih baru.');
                    }
                    $cursor = $import->processed;
                    $rows = array_slice($all, $cursor, 150);
                    $values = [];
                    foreach ($rows as $row) {
                        $values[] = [...$row, 'billing' => isset($row['billing']) ? json_encode($row['billing']) : null, 'id' => (string) Str::uuid7(), 'store_id' => $store->id, 'connection_id' => $c->id, 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now()];
                    }
                    SupplierProduct::upsert($values, ['connection_id', 'mode', 'sku'], ['name', 'kind', 'billing', 'service', 'category', 'cost', 'available', 'quantity', 'observed_at', 'updated_at']);
                    $import->update(['processed' => $cursor + count($rows)]);
                }, 3);
            }
            DB::transaction(function () use ($import, $payload, $all) {
                $store = Store::whereKey($import->store_id)->lockForUpdate()->firstOrFail();
                StoreRetention::guard($store);
                CatalogAccess::operational($store);
                abort_unless(CatalogAccess::supports($store, 'otomax'), 403);
                $connection = SupplierConnection::whereKey($import->connection_id)->lockForUpdate()->firstOrFail();
                if (! $connection->enabled || $this->fingerprint($connection) !== $payload['connection_hash'] || $store->sandbox !== $payload['sandbox'] || ($connection->synced_at?->toISOString() !== ($payload['catalog_synced_at'] ?? null))) {
                    throw new \RuntimeException('Koneksi berubah atau sudah ada impor lebih baru.');
                }
                if (($payload['missing'] ?? 'keep') === 'disable') {
                    $included = array_fill_keys(array_column($all, 'sku'), true);
                    SupplierProduct::where('connection_id', $import->connection_id)->select(['id', 'sku'])->chunkById(150, function ($products) use ($included) {
                        $missing = $products->filter(fn ($product) => ! isset($included[$product->sku]))->pluck('id');
                        if ($missing->isNotEmpty()) {
                            SupplierProduct::whereIn('id', $missing)->update(['available' => false]);
                        }
                    });
                }
                SupplierConnection::whereKey($import->connection_id)->update(['synced_at' => now(), 'last_error' => null]);
                $import->update(['status' => 'completed', 'payload' => array_intersect_key($payload, array_flip(['sandbox', 'profile', 'path', 'rows_path', 'rows_hash'])), 'error' => null]);
            }, 3);
            app(StoreCatalog::class)->reprice($c->store);
            $this->deleteFiles($import->fresh());
        } catch (\Throwable $error) {
            report($error);
            if ($import->fresh()?->status === 'completed') {
                return;
            }
            $import->update(['status' => 'failed', 'error' => 'Impor terhenti. Periksa akses, koneksi dan mode toko; buat pratinjau baru untuk melanjutkan.']);
        }
    }

    public function fingerprint(SupplierConnection $c): string
    {
        return hash_hmac('sha256', json_encode([$c->settings, $c->credentials, $c->enabled]), config('app.key'));
    }
}
