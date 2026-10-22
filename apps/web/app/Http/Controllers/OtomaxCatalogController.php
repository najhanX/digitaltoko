<?php

namespace App\Http\Controllers;

use App\Models\OtomaxImport;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Services\OtomaxCatalogSync;
use App\Services\OtomaxImports;
use App\Services\OtomaxWebCatalog;
use App\Services\StoreRetention;
use App\Support\CatalogAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OtomaxCatalogController extends Controller
{
    private function access(Request $request, Store $store, SupplierConnection $connection, string $permission): void
    {
        CatalogAccess::authorize($request, $store, $permission);
        CatalogAccess::operational($store);
        abort_unless(CatalogAccess::supports($store, 'otomax'), 403);
        abort_unless($connection->store_id === $store->id && $connection->kind === 'otomax', 404);
    }

    public function settings(Request $request, Store $store, SupplierConnection $connection): JsonResponse
    {
        $this->access($request, $store, $connection, 'products.view');
        $config = $connection->catalog_config;

        return response()->json(['configured' => (bool) $config, 'host' => $config ? parse_url($config['url'], PHP_URL_HOST) : null,
            'automatic' => $config['automatic'] ?? false, 'interval' => $config['interval'] ?? 15, 'profile' => $config['profile'] ?? OtomaxWebCatalog::defaults(),
            'revision' => $connection->catalog_revision, 'status' => $connection->catalog_status, 'error' => $connection->catalog_error])->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, Store $store, SupplierConnection $connection, OtomaxCatalogSync $sync): JsonResponse
    {
        $this->access($request, $store, $connection, 'suppliers.manage');
        abort_unless($connection->enabled, 422, 'Aktifkan koneksi supplier terlebih dahulu.');
        $data = $request->validate(['url' => 'nullable|string|max:2000', 'automatic' => 'required|boolean', 'interval' => ['required', 'integer', Rule::in([5, 15, 30, 60])],
            'profile' => 'required|array', 'profile.headers' => 'required|array', 'profile.headers.sku' => 'required|string|max:50', 'profile.headers.name' => 'required|string|max:50', 'profile.headers.cost' => 'required|string|max:50', 'profile.headers.status' => 'required|string|max:50',
            'profile.open_values' => 'required|string|max:250', 'profile.closed_values' => 'required|string|max:250', 'profile.service' => 'required|string|max:100']);
        $url = filled($data['url'] ?? null) ? trim($data['url']) : ($connection->catalog_config['url'] ?? '');
        abort_unless($url !== '', 422, 'Isi URL daftar ALL produk supplier.');
        $profile = ['headers' => array_intersect_key($data['profile']['headers'], array_flip(['sku', 'name', 'cost', 'status'])), ...array_intersect_key($data['profile'], array_flip(['open_values', 'closed_values', 'service']))];
        $record = $sync->preview($connection, ['url' => $url, 'automatic' => $data['automatic'], 'interval' => (int) $data['interval'], 'profile' => $profile], $this->actor($request));
        $rows = app(OtomaxImports::class)->previewRows($record, $record->payload);

        return response()->json(['id' => $record->id, 'summary' => $record->summary, 'sample' => array_slice($rows, 0, 50), 'observed_at' => $record->payload['observed_at']])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Store $store, SupplierConnection $connection, OtomaxCatalogSync $sync): RedirectResponse
    {
        $this->access($request, $store, $connection, 'suppliers.manage');
        $data = $request->validate(['preview_id' => 'required|uuid', 'accept_missing' => 'required|boolean']);
        $preview = OtomaxImport::where('store_id', $store->id)->where('connection_id', $connection->id)->findOrFail($data['preview_id']);
        $sync->activate($connection, $preview, $this->actor($request), $data['accept_missing']);

        return back()->with('success', 'URL daftar harga tersimpan. Sinkronisasi masuk antrean.');
    }

    public function sync(Request $request, Store $store, SupplierConnection $connection, OtomaxCatalogSync $sync): RedirectResponse
    {
        $this->access($request, $store, $connection, 'suppliers.import');
        $record = $sync->enqueue($connection);

        return back()->with('success', $record ? 'Sinkronisasi daftar harga masuk antrean.' : 'Sinkronisasi supplier ini masih dalam antrean atau sedang diproses.');
    }

    public function pause(Request $request, Store $store, SupplierConnection $connection): RedirectResponse
    {
        $this->access($request, $store, $connection, 'suppliers.manage');
        DB::transaction(function () use ($store, $connection) {
            StoreRetention::guard(Store::whereKey($store->id)->lockForUpdate()->firstOrFail());
            $locked = SupplierConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($config = $locked->catalog_config) {
                $locked->update(['catalog_config' => [...$config, 'automatic' => false], 'catalog_revision' => $locked->catalog_revision + 1, 'catalog_next_sync_at' => null, 'catalog_status' => 'paused']);
            }
        });

        return back()->with('success', 'Sinkronisasi otomatis dihentikan. Produk dan harga terakhir tetap disimpan.');
    }

    public function detail(Request $request, Store $store, OtomaxImport $import): JsonResponse
    {
        CatalogAccess::authorize($request, $store);
        abort_unless($import->store_id === $store->id && $import->source === 'url', 404);

        return response()->json($import->only(['id', 'filename', 'status', 'summary', 'processed', 'error', 'created_at', 'updated_at']))->header('Cache-Control', 'private, no-store');
    }

    private function actor(Request $request): string
    {
        return $request->user('staff') ? 'staff:'.$request->user('staff')->id : 'owner:'.$request->user()->id;
    }
}
