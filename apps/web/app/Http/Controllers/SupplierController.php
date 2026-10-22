<?php

namespace App\Http\Controllers;

use App\Models\CustomerOrder;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Services\SupplierCatalog;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $role = CatalogAccess::authorize($request, $store);
        $connections = SupplierConnection::where('store_id', $store->id)->orderBy('kind')->get();
        $list = new ListQuery($request, ['connection' => $connections->pluck('id')->all(), 'available' => ['1', '0'], 'mode' => ['prepaid', 'pasca']], 10);
        $page = $list->paginate($list->apply(SupplierProduct::where('store_id', $store->id), ['sku', 'name', 'category', 'service'], ['connection' => 'connection_id', 'available' => 'available', 'mode' => 'mode'])->orderBy('name')->orderBy('id'));

        return Inertia::render('Owner/Sources', ['role' => $role, 'store' => $store->only(['id', 'name', 'plan', 'status', 'sandbox']),
            'connections' => $connections->map(fn ($item) => [...$item->only(['id', 'name', 'kind', 'enabled', 'verified_at', 'synced_at', 'last_error']), 'webhook_configured' => filled($item->credentials['webhook_secret'] ?? null)]),
            'digiflazzWebhookUrl' => config('suppliers.digiflazz_webhook_url') ?: rtrim(config('app.url'), '/').'/api/commerce/digiflazz',
            'features' => array_values(array_filter(['digiflazz', 'stock', 'hanshop', 'otomax'], fn ($kind) => CatalogAccess::supports($store, $kind))),
            'canManage' => CatalogAccess::has($request, $role, 'products.manage'), 'items' => $page->getCollection()->map(fn ($item) => $item->only(['id', 'connection_id', 'sku', 'mode', 'name', 'kind', 'service', 'category', 'cost', 'available', 'availability_reason', 'quantity', 'observed_at'])),
            'filters' => $list->filters, 'pagination' => ListQuery::meta($page)]);
    }

    public function save(Request $request, Store $store, SupplierCatalog $catalog): RedirectResponse
    {
        abort_unless(CatalogAccess::authorize($request, $store) === 'owner', 403);
        CatalogAccess::operational($store);
        $data = $request->validate(['kind' => 'required|in:digiflazz,hanshop', 'username' => 'nullable|string|max:100', 'api_key' => 'nullable|string|max:255', 'enabled' => 'required|boolean']);
        abort_unless(CatalogAccess::supports($store, $data['kind']), 403);
        $connection = SupplierConnection::firstOrNew(['store_id' => $store->id, 'kind' => $data['kind']]);
        $credentials = $connection->credentials ?? [];
        if ($data['kind'] === 'digiflazz') {
            $username = trim(filled($data['username'] ?? null) ? $data['username'] : ($credentials['username'] ?? ''));
            $keyField = $store->sandbox ? 'development_key' : 'api_key';
            $key = filled($data['api_key'] ?? null) ? $data['api_key'] : ($credentials[$keyField] ?? '');
            if (! $username || ! $key) {
                throw ValidationException::withMessages(['supplier' => 'Username dan API key Digiflazz wajib diisi.']);
            }
            $credentials = [...$credentials, 'username' => $username, $keyField => $key];
        }
        $connection->fill(['credentials' => $data['kind'] === 'digiflazz' ? $credentials : null, 'enabled' => $data['enabled']]);
        $connection->setRelation('store', $store->loadMissing('owner'));
        if ($connection->enabled) {
            $catalog->balance($connection);
        }
        DB::transaction(function () use ($store, $connection) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $fresh = $store->fresh();
            CatalogAccess::operational($fresh);
            abort_unless($fresh->sandbox === $store->sandbox, 409);
            $changed = $connection->isDirty('credentials') || ! $connection->exists;
            $connection->verified_at = $connection->enabled && ! $store->sandbox ? now() : null;
            $connection->save();
            if ($changed || ! $connection->enabled) {
                SupplierProduct::where('connection_id', $connection->id)->update(['available' => false]);
                $connection->update(['synced_at' => null, 'last_error' => null]);
            }
        });

        return back()->with('success', 'Koneksi pemasok tersimpan. Sinkronkan katalog untuk memperbarui produknya.');
    }

    public function sync(Request $request, Store $store, SupplierConnection $connection, SupplierCatalog $catalog): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        abort_unless($connection->store_id === $store->id, 404);
        $data = $request->validate(['mode' => 'required|in:prepaid,pasca']);
        $count = $catalog->sync($connection, $data['mode']);

        return back()->with('success', $count.' produk pemasok berhasil disinkronkan.');
    }

    public function disconnect(Request $request, Store $store, SupplierConnection $connection): RedirectResponse
    {
        abort_unless(CatalogAccess::authorize($request, $store) === 'owner', 403);
        abort_unless($connection->store_id === $store->id, 404);
        abort_if($connection->kind === 'otomax', 422, 'Nonaktifkan OtomaX melalui pengaturan koneksinya agar callback lama tetap dapat diterima.');
        DB::transaction(function () use ($connection) {
            $locked = SupplierConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $secret = $locked->credentials['webhook_secret'] ?? null;
            $locked->update(['enabled' => false, 'credentials' => $secret ? ['webhook_secret' => $secret] : null, 'verified_at' => null]);
            SupplierProduct::where('connection_id', $connection->id)->update(['available' => false]);
        });

        return back()->with('success', 'Koneksi pemasok dinonaktifkan dan credential dihapus.');
    }

    public function webhookSecret(Request $request, Store $store, SupplierConnection $connection): JsonResponse
    {
        abort_unless(CatalogAccess::authorize($request, $store) === 'owner', 403);
        abort_unless($connection->store_id === $store->id && $connection->kind === 'digiflazz', 404);
        $secret = $connection->credentials['webhook_secret'] ?? null;
        abort_unless(is_string($secret) && strlen($secret) >= 16, 404, 'Secret webhook belum dikonfigurasi.');

        return response()->json(['secret' => $secret])->header('Cache-Control', 'private, no-store');
    }

    public function webhook(Request $request, Store $store, SupplierConnection $connection): RedirectResponse
    {
        abort_unless(CatalogAccess::authorize($request, $store) === 'owner', 403);
        abort_unless($connection->store_id === $store->id && $connection->kind === 'digiflazz', 404);
        $data = $request->validate(['webhook_secret' => 'required|string|min:16|max:255']);
        DB::transaction(function () use ($store, $connection, $data) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $locked = SupplierConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $credentials = $locked->credentials ?? [];
            if (filled($credentials['webhook_secret'] ?? null) && $credentials['webhook_secret'] !== $data['webhook_secret'] && CustomerOrder::where('store_id', $store->id)->whereIn('status', ['paid', 'processing', 'pending', 'review'])->exists()) {
                throw ValidationException::withMessages(['webhook_secret' => 'Selesaikan transaksi berjalan sebelum mengganti secret webhook.']);
            }
            $locked->update(['credentials' => [...$credentials, 'webhook_secret' => $data['webhook_secret']]]);
        });

        return back()->with('success', 'Secret webhook tersimpan. Pasang URL dan secret yang sama pada panel Digiflazz.');
    }

    public function balances(Request $request, Store $store, SupplierCatalog $catalog): JsonResponse
    {
        $role = CatalogAccess::authorize($request, $store, $request->user('staff') ? 'suppliers.balance' : 'products.view');
        $result = [];
        foreach (['digiflazz', 'hanshop'] as $kind) {
            if ($role === 'staff' && $kind === 'hanshop') {
                $result[$kind] = ['status' => 'restricted'];

                continue;
            }
            if ($store->admin_suspended_at || (! $store->acceptsOrders() && $store->status !== 'provisioning')) {
                $result[$kind] = ['status' => 'inactive'];

                continue;
            }
            if (! CatalogAccess::supports($store, $kind)) {
                $result[$kind] = ['status' => 'unsupported'];

                continue;
            }
            $connection = SupplierConnection::where('store_id', $store->id)->where('kind', $kind)->where('enabled', true)->first();
            if (! $connection) {
                $result[$kind] = ['status' => 'disconnected'];

                continue;
            }
            try {
                $result[$kind] = ['status' => 'connected', ...$catalog->balance($connection)];
            } catch (\Throwable $error) {
                $result[$kind] = ['status' => 'error', 'message' => 'Saldo belum dapat dibaca. Periksa koneksi pemasok.'];
            }
        }

        return response()->json(['data' => $result])->header('Cache-Control', 'private, no-store');
    }
}
