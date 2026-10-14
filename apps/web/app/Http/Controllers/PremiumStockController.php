<?php

namespace App\Http\Controllers;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Services\StoreCatalog;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PremiumStockController extends Controller
{
    public function index(Request $request, Store $store): Response
    {
        $role = CatalogAccess::authorize($request, $store, 'stock.view');
        $list = new ListQuery($request, ['status' => ['ready', 'sent']], 10);
        $query = PremiumStock::with('product')->where('store_id', $store->id);
        $product = $request->query('product');
        if (is_string($product) && $product !== '') {
            abort_unless(CatalogProduct::where('store_id', $store->id)->whereKey($product)->exists(), 404);
            $query->where('product_id', $product);
        }
        $page = $list->paginate($list->apply($query, ['note', 'order_reference'], ['status' => 'status'])->latest()->orderByDesc('id'));
        Inertia::encryptHistory();

        return Inertia::render('Owner/Stock', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']),
            'canManage' => CatalogAccess::has($request, $role, 'stock.manage'), 'canReveal' => CatalogAccess::has($request, $role, 'stock.credentials'),
            'supported' => CatalogAccess::supports($store, 'stock'), 'selectedProduct' => $product,
            'stocks' => $page->getCollection()->map(fn ($stock) => [...$stock->only(['id', 'product_id', 'cost', 'status', 'note', 'order_reference', 'created_at']), 'product' => $stock->product?->only(['id', 'code', 'name'])]),
            'filters' => $list->filters, 'pagination' => ListQuery::meta($page)])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function products(Request $request, Store $store): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'stock.manage');
        $list = new ListQuery($request, [], 10);
        $page = $list->paginate($list->apply(CatalogProduct::where('store_id', $store->id)->where('kind', 'premium'), ['code', 'name'])->orderBy('name')->orderBy('id'));

        return response()->json(['items' => $page->getCollection()->map(fn ($p) => $p->only(['id', 'code', 'name'])), 'pagination' => ListQuery::meta($page)])->header('Cache-Control', 'private, no-store');
    }

    public function add(Request $request, Store $store, StoreCatalog $catalog): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'stock.manage');
        CatalogAccess::operational($store);
        abort_unless(CatalogAccess::supports($store, 'stock'), 403);
        $data = $request->validate(['product_id' => 'required|uuid', 'cost' => 'required|integer|min:0|max:1000000000000', 'note' => 'nullable|string|max:250',
            'entries' => 'required|array|min:1|max:100', 'entries.*' => 'required|string|min:1|max:10000']);
        DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($locked);
            abort_unless(CatalogAccess::supports($locked, 'stock'), 403);
            $product = CatalogProduct::where('store_id', $store->id)->where('kind', 'premium')->whereKey($data['product_id'])->lockForUpdate()->firstOrFail();
            foreach ($data['entries'] as $payload) {
                PremiumStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'payload' => $payload, 'cost' => $data['cost'], 'note' => $data['note'] ?? null]);
            }
            ProductMapping::firstOrCreate(['product_id' => $product->id, 'source_key' => 'stock'], ['store_id' => $store->id, 'source' => 'stock', 'active' => true]);
        });
        $catalog->reprice($store);

        return back()->with('success', count($data['entries']).' stok privat berhasil ditambahkan.');
    }

    public function update(Request $request, Store $store, PremiumStock $stock, StoreCatalog $catalog): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'stock.manage');
        CatalogAccess::operational($store);
        abort_unless(CatalogAccess::supports($store, 'stock'), 403);
        abort_unless($stock->store_id === $store->id, 404);
        $data = $request->validate(['cost' => 'required|integer|min:0|max:1000000000000', 'note' => 'nullable|string|max:250', 'payload' => 'nullable|string|min:1|max:10000']);
        if (($data['payload'] ?? null) === null || $data['payload'] === '') {
            unset($data['payload']);
        }
        DB::transaction(function () use ($stock, $data) {
            $locked = PremiumStock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'ready' && ! $locked->order_reference, 422, 'Stok yang sudah terkirim tidak dapat diedit.');
            $locked->update($data);
        });
        $catalog->reprice($store);

        return back()->with('success', 'Stok ready berhasil diperbarui.');
    }

    public function delete(Request $request, Store $store, PremiumStock $stock, StoreCatalog $catalog): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'stock.manage');
        CatalogAccess::operational($store);
        abort_unless(CatalogAccess::supports($store, 'stock'), 403);
        abort_unless($stock->store_id === $store->id, 404);
        DB::transaction(function () use ($stock) {
            $locked = PremiumStock::whereKey($stock->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'ready' && ! $locked->order_reference, 422, 'Stok yang sudah terkirim tidak dapat dihapus.');
            $locked->delete();
        });
        $catalog->reprice($store);

        return back()->with('success', 'Stok ready dihapus.');
    }

    public function reveal(Request $request, Store $store, PremiumStock $stock): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'stock.credentials');
        abort_unless($stock->store_id === $store->id, 404);

        return response()->json(['data' => ['id' => $stock->id, 'payload' => $stock->payload]])->header('Cache-Control', 'private, no-store');
    }
}
