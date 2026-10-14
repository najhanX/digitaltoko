<?php

namespace App\Http\Controllers;

use App\Models\CatalogProduct;
use App\Models\PremiumStock;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierProduct;
use App\Services\ProductPricing;
use App\Services\StoreCatalog;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request, Store $store, StoreCatalog $catalog): Response
    {
        $role = CatalogAccess::authorize($request, $store);
        $list = new ListQuery($request, ['kind' => ['ppob', 'premium', 'postpaid'], 'active' => ['1', '0']], 10);
        $page = $list->paginate($list->apply($catalog->query($store), ['code', 'name', 'service', 'category'], ['kind' => 'kind', 'active' => 'active'])->orderBy('name')->orderBy('id'));
        $rulesList = new ListQuery($request, ['scope' => ['category', 'service']], 10, 'rules_');
        $rules = $rulesList->paginate($rulesList->apply(PricingRule::where('store_id', $store->id), ['key', 'markup'], ['scope' => 'scope'])->orderBy('key')->orderBy('id'));

        return Inertia::render('Owner/Catalog', ['role' => $role, 'store' => $store->only(['id', 'name', 'status']),
            'canManage' => CatalogAccess::has($request, $role, 'products.manage'), 'canPrice' => CatalogAccess::has($request, $role, 'pricing.manage'),
            'canStock' => CatalogAccess::supports($store, 'stock'), 'products' => $page->getCollection()->map(fn ($p) => $catalog->present($p, $store, true)),
            'filters' => $list->filters, 'pagination' => ListQuery::meta($page), 'rules' => $rules->items(), 'ruleFilters' => $rulesList->filters, 'rulePagination' => ListQuery::meta($rules)]);
    }

    public function save(Request $request, Store $store, StoreCatalog $catalog, ProductPricing $pricing, ?CatalogProduct $product = null): RedirectResponse
    {
        $role = CatalogAccess::authorize($request, $store, 'products.manage');
        CatalogAccess::operational($store);
        abort_if($product && $product->store_id !== $store->id, 404);
        $canPrice = CatalogAccess::has($request, $role, 'pricing.manage');
        abort_if(! $canPrice && $request->hasAny(['sale_price', 'markup', 'auto_update']), 403);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z0-9][A-Z0-9_.-]{0,49}$/', Rule::unique('catalog_products')->where('store_id', $store->id)->ignore($product?->id)],
            'name' => 'required|string|max:150', 'description' => 'nullable|string|max:3000', 'kind' => 'required|in:ppob,premium,postpaid',
            'service' => 'required|string|max:100', 'category' => 'required|string|max:100', 'active' => 'required|boolean',
            'mapping_ids' => 'present|array|max:20', 'mapping_ids.*' => 'required|uuid|distinct', 'use_stock' => 'required|boolean',
            'sale_price' => 'sometimes|nullable|integer|min:0|max:1000000000000', 'markup' => 'sometimes|nullable|string|max:100', 'auto_update' => 'sometimes|nullable|boolean']);
        if ($data['use_stock']) {
            abort_unless($data['kind'] === 'premium' && CatalogAccess::supports($store, 'stock'), 403);
        }
        $items = SupplierProduct::with('connection')->where('store_id', $store->id)->whereIn('id', $data['mapping_ids'])->get();
        abort_unless($items->count() === count($data['mapping_ids']), 422);
        foreach ($items as $item) {
            abort_unless($item->kind === $data['kind'] && $item->connection->enabled && CatalogAccess::supports($store, $item->connection->kind), 422, 'Parsing tidak sesuai jenis produk atau paket.');
        }
        if ($canPrice && array_key_exists('markup', $data)) {
            $data['markup'] = $pricing->parse($data['markup'])['text'] ?? null;
        }
        DB::transaction(function () use ($store, $product, $data, $items, $pricing) {
            $lockedStore = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($lockedStore);
            if ($product) {
                $product = CatalogProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ($product->kind !== $data['kind']) {
                    abort_if(PremiumStock::where('product_id', $product->id)->exists(), 422, 'Jenis produk yang memiliki stok tidak dapat diganti.');
                }
            }
            $new = ! $product;
            $values = Arr::except($data, ['mapping_ids', 'use_stock']);
            if (($values['sale_price'] ?? null) === null) {
                unset($values['sale_price']);
            }
            $product ??= new CatalogProduct(['store_id' => $store->id]);
            $product->fill($values)->save();
            $keys = [];
            foreach ($items as $item) {
                $keys[] = 'item:'.$item->id;
                ProductMapping::updateOrCreate(['product_id' => $product->id, 'source_key' => 'item:'.$item->id],
                    ['store_id' => $store->id, 'supplier_product_id' => $item->id, 'source' => $item->connection->kind, 'active' => true]);
            }
            if ($data['use_stock']) {
                $keys[] = 'stock';
                ProductMapping::updateOrCreate(['product_id' => $product->id, 'source_key' => 'stock'], ['store_id' => $store->id, 'source' => 'stock', 'supplier_product_id' => null, 'active' => true]);
            }
            ProductMapping::where('product_id', $product->id)->whereNotIn('source_key', $keys)->delete();
            if ($new && ! array_key_exists('sale_price', $values) && $items->isNotEmpty()) {
                $product->update(['sale_price' => $pricing->apply((int) $items->min('cost'), $pricing->resolve($product)['markup'])]);
            }
        });
        $catalog->reprice($store);

        return back()->with('success', 'Produk dan parsing tersimpan.');
    }

    public function import(Request $request, Store $store, ProductPricing $pricing, StoreCatalog $catalog): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        CatalogAccess::operational($store);
        $data = $request->validate(['ids' => 'required|array|min:1|max:50', 'ids.*' => 'required|uuid|distinct']);
        $count = DB::transaction(function () use ($store, $data, $pricing) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($store);
            $items = SupplierProduct::with('connection')->where('store_id', $store->id)->whereIn('id', $data['ids'])->get();
            abort_unless($items->count() === count($data['ids']), 422);
            $count = 0;
            foreach ($items as $item) {
                abort_unless($item->connection->enabled && CatalogAccess::supports($store, $item->connection->kind), 403);
                if (ProductMapping::where('store_id', $store->id)->where('supplier_product_id', $item->id)->exists()) {
                    continue;
                }
                $code = ($item->connection->kind === 'digiflazz' ? 'D' : 'H').'-'.strtoupper(substr(preg_replace('/[^a-zA-Z0-9_.-]/', '-', $item->sku), 0, 32)).'-'.strtoupper(substr(hash('sha256', $item->id), 0, 8));
                $originalCode = $code;
                while (CatalogProduct::where('store_id', $store->id)->where('code', $code)->exists()) {
                    $code = $originalCode.'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
                }
                $product = new CatalogProduct(['store_id' => $store->id, 'code' => $code, 'name' => $item->name, 'kind' => $item->kind, 'service' => $item->service, 'category' => $item->category, 'active' => true]);
                $product->sale_price = $pricing->apply($item->cost, $pricing->resolve($product)['markup']);
                $product->save();
                ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'supplier_product_id' => $item->id, 'source' => $item->connection->kind, 'source_key' => 'item:'.$item->id]);
                $count++;
            }

            return $count;
        });
        $catalog->reprice($store);

        return back()->with('success', $count.' produk baru ditambahkan. Produk yang sudah diparsing dilewati.');
    }

    public function delete(Request $request, Store $store, CatalogProduct $product): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        CatalogAccess::operational($store);
        abort_unless($product->store_id === $store->id, 404);
        DB::transaction(function () use ($product) {
            CatalogProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
            abort_if(PremiumStock::where('product_id', $product->id)->where('status', 'sent')->exists(), 422, 'Produk dengan stok terkirim harus dinonaktifkan, bukan dihapus.');
            $product->delete();
        });

        return back()->with('success', 'Produk, parsing, dan stok ready terkait dihapus.');
    }

    public function price(Request $request, Store $store, CatalogProduct $product, ProductPricing $pricing, StoreCatalog $catalog): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'pricing.manage');
        CatalogAccess::operational($store);
        abort_unless($product->store_id === $store->id, 404);
        $data = $request->validate(['sale_price' => 'required|integer|min:0|max:1000000000000', 'markup' => 'nullable|string|max:100', 'auto_update' => 'present|nullable|boolean']);
        $data['markup'] = $pricing->parse($data['markup'] ?? null)['text'] ?? null;
        $product->update($data);
        $catalog->reprice($store);

        return back()->with('success', 'Harga produk berhasil diperbarui.');
    }

    public function mappings(Request $request, Store $store): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        $list = new ListQuery($request, ['kind' => ['ppob', 'premium', 'postpaid']], 10);
        $page = $list->paginate($list->apply(SupplierProduct::with('connection')->where('store_id', $store->id)->whereHas('connection', fn ($q) => $q->where('enabled', true)), ['name', 'sku'], ['kind' => 'kind'])->orderBy('name')->orderBy('id'));

        return response()->json(['items' => $page->getCollection()->map(fn ($item) => [...$item->only(['id', 'name', 'sku', 'mode', 'cost', 'kind']), 'source' => $item->connection->kind, 'allowed' => CatalogAccess::supports($store, $item->connection->kind)]), 'pagination' => ListQuery::meta($page)])->header('Cache-Control', 'private, no-store');
    }

    public function saveRule(Request $request, Store $store, ProductPricing $pricing, StoreCatalog $catalog, ?PricingRule $rule = null): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'pricing.manage');
        CatalogAccess::operational($store);
        abort_if($rule && $rule->store_id !== $store->id, 404);
        $request->merge(['key' => mb_strtolower(trim((string) $request->input('key')))]);
        $data = $request->validate(['scope' => 'required|in:category,service', 'key' => ['required', 'string', 'max:100', Rule::unique('pricing_rules')->where('store_id', $store->id)->where('scope', $request->input('scope'))->ignore($rule?->id)],
            'markup' => 'nullable|string|max:100', 'auto_update' => 'present|nullable|boolean']);
        $data['markup'] = $pricing->parse($data['markup'] ?? null)['text'] ?? null;
        $rule ? $rule->update($data) : PricingRule::create([...$data, 'store_id' => $store->id]);
        $catalog->reprice($store);

        return back()->with('success', 'Aturan harga tersimpan. Prioritas produk → kategori → layanan.');
    }

    public function deleteRule(Request $request, Store $store, PricingRule $rule, StoreCatalog $catalog): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'pricing.manage');
        CatalogAccess::operational($store);
        abort_unless($rule->store_id === $store->id, 404);
        $rule->delete();
        $catalog->reprice($store);

        return back()->with('success', 'Aturan harga dihapus.');
    }
}
