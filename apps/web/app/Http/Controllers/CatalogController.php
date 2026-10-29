<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CustomerOrder;
use App\Models\PremiumStock;
use App\Models\PricingRule;
use App\Models\ProductMapping;
use App\Models\Store;
use App\Models\SupplierConnection;
use App\Models\SupplierProduct;
use App\Services\ImportedCategoryImage;
use App\Services\PostpaidBilling;
use App\Services\ProductPricing;
use App\Services\StoreCatalog;
use App\Support\CatalogAccess;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request, Store $store, StoreCatalog $catalog): Response
    {
        $role = CatalogAccess::authorize($request, $store);
        $list = new ListQuery($request, ['kind' => ['ppob', 'premium', 'postpaid'], 'active' => ['1', '0']], 10);
        $categoryId = $request->query('category_id');
        $selectedCategory = is_string($categoryId) && $categoryId !== '' ? CatalogCategory::with('service:id,name')->where('store_id', $store->id)->findOrFail($categoryId) : null;
        $query = $catalog->query($store);
        if ($selectedCategory) {
            $query->where('category_id', $selectedCategory->id);
        }
        $page = $list->paginate($list->apply($query, ['code', 'name', 'service', 'category'], ['kind' => 'kind', 'active' => 'active'])->orderBy('name')->orderBy('id'));
        $rulesList = new ListQuery($request, ['scope' => ['category', 'service']], 10, 'rules_');
        $rules = $rulesList->paginate($rulesList->apply(PricingRule::where('store_id', $store->id), ['key', 'markup'], ['scope' => 'scope'])->orderBy('key')->orderBy('id'));

        return Inertia::render('Owner/Catalog', ['selectedCategory' => $selectedCategory?->only(['id', 'name', 'service']), 'role' => $role, 'store' => $store->only(['id', 'name', 'status']),
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
        abort_if(! $canPrice && $request->hasAny(['sale_price', 'markup', 'auto_update', 'pricing_basis']), 403);
        $request->validate(['category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('catalog_categories', 'id')->where('store_id', $store->id)]]);
        if ($request->filled('category_id')) {
            $category = CatalogCategory::with('service')->where('store_id', $store->id)->findOrFail($request->input('category_id'));
            $request->merge(['service' => $category->service->name, 'category' => $category->name]);
        }
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z0-9][A-Z0-9_.-]{0,49}$/', Rule::unique('catalog_products')->where('store_id', $store->id)->ignore($product?->id)],
            'name' => 'required|string|max:150', 'description' => 'nullable|string|max:3000', 'kind' => 'required|in:ppob,premium,postpaid',
            'category_id' => 'sometimes|nullable|uuid', 'service' => 'required|string|max:100', 'category' => 'required|string|max:100', 'active' => 'required|boolean',
            'postpaid_pairs' => 'sometimes|array|max:20', 'postpaid_pairs.*.inquiry_id' => 'required|uuid', 'postpaid_pairs.*.admin' => 'required|integer|min:0|max:100000000',
            'mapping_ids' => 'present|array|max:20', 'mapping_ids.*' => 'required|uuid|distinct', 'use_stock' => 'required|boolean',
            'sale_price' => 'sometimes|nullable|integer|min:0|max:1000000000000', 'markup' => 'sometimes|nullable|string|max:100', 'auto_update' => 'sometimes|nullable|boolean', 'pricing_basis' => 'prohibited']);
        if ($data['use_stock']) {
            abort_unless($data['kind'] === 'premium' && CatalogAccess::supports($store, 'stock'), 403);
        }
        $items = SupplierProduct::with('connection')->where('store_id', $store->id)->whereIn('id', $data['mapping_ids'])->get();
        abort_unless($items->count() === count($data['mapping_ids']), 422);
        foreach ($items as $item) {
            abort_unless(($item->kind === $data['kind'] || ($data['kind'] === 'postpaid' && $item->connection->kind === 'otomax' && $item->kind === 'ppob')) && $item->connection->enabled && CatalogAccess::supports($store, $item->connection->kind), 422, 'Parsing tidak sesuai jenis produk atau paket.');
        }
        abort_if(array_diff(array_keys($data['postpaid_pairs'] ?? []), $data['mapping_ids']), 422, 'Pengaturan pascabayar tidak sesuai parsing terpilih.');
        foreach ($items as $item) {
            if ($data['kind'] !== 'postpaid' || $item->connection->kind !== 'otomax') {
                continue;
            }
            $pair = $data['postpaid_pairs'][$item->id] ?? null;
            if (! $pair || ! SupplierProduct::where('store_id', $store->id)->where('connection_id', $item->connection_id)->where('cost', 0)->where('id', '!=', $item->id)->where('available', true)
                ->where(fn ($q) => $q->whereNull('billing')->orWhere('billing->adjustment', 0))->whereKey($pair['inquiry_id'])->exists()) {
                throw ValidationException::withMessages(['postpaid_pairs' => 'Pilih produk cek gratis pada supplier yang sama dan isi biaya admin untuk setiap parsing OtomaX.']);
            }
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
            $values = Arr::except($data, ['mapping_ids', 'use_stock', 'postpaid_pairs']);
            if (($values['sale_price'] ?? null) === null) {
                unset($values['sale_price']);
            }
            $product ??= new CatalogProduct(['store_id' => $store->id]);
            $product->fill($values)->save();
            $keys = [];
            foreach ($items as $item) {
                $keys[] = 'item:'.$item->id;
                ProductMapping::updateOrCreate(['product_id' => $product->id, 'source_key' => 'item:'.$item->id],
                    ['store_id' => $store->id, 'supplier_product_id' => $item->id, 'source' => $item->connection->kind, 'active' => true, 'postpaid' => $data['kind'] === 'postpaid' && $item->connection->kind === 'otomax' ? ['inquiry_id' => $data['postpaid_pairs'][$item->id]['inquiry_id'], 'admin' => (int) $data['postpaid_pairs'][$item->id]['admin']] : null]);
            }
            if ($data['use_stock']) {
                $keys[] = 'stock';
                ProductMapping::updateOrCreate(['product_id' => $product->id, 'source_key' => 'stock'], ['store_id' => $store->id, 'source' => 'stock', 'supplier_product_id' => null, 'active' => true]);
            }
            ProductMapping::where('product_id', $product->id)->whereNotIn('source_key', $keys)->delete();
            if ($new && ! array_key_exists('sale_price', $values) && $items->isNotEmpty()) {
                $markup = $pricing->resolve($product)['markup'];
                if ($data['kind'] === 'postpaid') {
                    $sorted = $items->sortBy(fn ($item) => ($item->connection->kind === 'otomax' ? (int) $data['postpaid_pairs'][$item->id]['admin'] : $item->cost) + app(PostpaidBilling::class)->sourceAdjustment($item->connection->kind, $item->billing));
                    $first = $pricing->resolve($product)['pricing_basis'] === 'cheapest' ? $sorted->first() : $sorted->last();
                    $admin = $first->connection->kind === 'otomax' ? (int) $data['postpaid_pairs'][$first->id]['admin'] : $first->cost;
                    $billing = app(PostpaidBilling::class);
                    $product->update(['sale_price' => $admin + $billing->sourceAdjustment($first->connection->kind, $first->billing) + $billing->storeFee($billing->sourceAdjustment($first->connection->kind, $first->billing), $markup)]);
                } else {
                    $product->update(['sale_price' => $pricing->apply((int) ($pricing->resolve($product)['pricing_basis'] === 'cheapest' ? $items->min('cost') : $items->max('cost')), $markup)]);
                }
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

            return $this->importItems($store, $items, $pricing);
        });
        $catalog->reprice($store);

        return back()->with('success', $count.' produk baru ditambahkan. Produk yang sudah diparsing dilewati.');
    }

    public function importAll(Request $request, Store $store, SupplierConnection $connection, ProductPricing $pricing): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        abort_unless($connection->store_id === $store->id, 404);
        $data = $request->validate(['through' => 'nullable|uuid', 'scope' => 'sometimes|required|in:all,service,category',
            'service' => 'required_if:scope,service,category|nullable|string|max:100', 'category' => 'required_if:scope,category|nullable|string|max:100']);
        $result = DB::transaction(function () use ($store, $connection, $pricing, $data) {
            $store = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($store);
            $connection = SupplierConnection::whereKey($connection->id)->firstOrFail();
            abort_unless($connection->enabled && CatalogAccess::supports($store, $connection->kind), 403);
            $query = SupplierProduct::where('store_id', $store->id)->where('connection_id', $connection->id);
            if (in_array($data['scope'] ?? 'all', ['service', 'category'], true)) {
                $query->where('service', $data['service']);
                if (($data['scope'] ?? '') === 'category') {
                    $query->where('category', $data['category']);
                }
                if (! (clone $query)->exists()) {
                    throw ValidationException::withMessages(['scope' => 'Layanan/kategori tidak ditemukan pada sumber ini. Pilih ulang dari katalog pemasok.']);
                }
            }
            $through = $data['through'] ?? (clone $query)->max('id');
            if (! $through) {
                return ['imported' => 0, 'remaining' => 0, 'total' => 0, 'through' => null];
            }
            $query->where('id', '<=', $through);
            $total = (clone $query)->count();
            $query->whereNotIn('id', ProductMapping::where('store_id', $store->id)->whereNotNull('supplier_product_id')->select('supplier_product_id'));
            $items = (clone $query)->with('connection')->orderBy('id')->limit(100)->get();
            $count = $this->importItems($store, $items, $pricing);

            return ['imported' => $count, 'remaining' => $query->count(), 'total' => $total, 'through' => $through];
        });

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function importOptions(Request $request, Store $store, SupplierConnection $connection): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        abort_unless($connection->store_id === $store->id, 404);
        CatalogAccess::operational($store);
        abort_unless($connection->enabled && CatalogAccess::supports($store, $connection->kind), 403);
        $data = $request->validate(['type' => 'required|in:services,categories', 'service' => 'required_if:type,categories|nullable|string|max:100']);
        $column = $data['type'] === 'services' ? 'service' : 'category';
        $query = SupplierProduct::where('store_id', $store->id)->where('connection_id', $connection->id);
        if ($column === 'category') {
            $query->where('service', $data['service']);
        }
        $list = new ListQuery($request, [], 5);
        $query->selectRaw($column.' as id, '.$column.' as name')->groupBy($column);
        $options = $list->options($list->apply($query, [$column])->orderBy($column));

        return response()->json($options)->header('Cache-Control', 'private, no-store');
    }

    private function importItems(Store $store, Collection $items, ProductPricing $pricing): int
    {
        $count = 0;
        $knownCategories = array_fill_keys(CatalogCategory::where('store_id', $store->id)->pluck('id')->all(), true);
        $branding = $store->branding ?? [];
        $imagesChanged = false;
        foreach ($items as $item) {
            abort_unless($item->connection->enabled && CatalogAccess::supports($store, $item->connection->kind), 403);
            if (ProductMapping::where('store_id', $store->id)->where('supplier_product_id', $item->id)->exists()) {
                continue;
            }
            $code = (['digiflazz' => 'D', 'hanshop' => 'H', 'otomax' => 'O'][$item->connection->kind] ?? 'S').'-'.strtoupper(substr(preg_replace('/[^a-zA-Z0-9_.-]/', '-', $item->sku), 0, 48));
            $originalCode = $code;
            $counter = 1;
            while (CatalogProduct::where('store_id', $store->id)->where('code', $code)->exists()) {
                $suffix = $item->kind === 'postpaid' ? '-PASCA'.($counter > 1 ? '-'.$counter : '') : '-'.($counter + 1);
                $code = substr($originalCode, 0, 50 - strlen($suffix)).$suffix;
                $counter++;
            }
            $product = new CatalogProduct(['store_id' => $store->id, 'code' => $code, 'name' => $item->name, 'kind' => $item->kind, 'service' => $item->service, 'category' => $item->category, 'active' => ! ($item->kind === 'postpaid' && $item->connection->kind === 'otomax')]);
            $product->sale_price = $pricing->apply($item->cost, $pricing->resolve($product)['markup']);
            $product->save();
            if (! isset($knownCategories[$product->category_id])) {
                $knownCategories[$product->category_id] = true;
                $image = app(ImportedCategoryImage::class)->resolve($product->category);
                if ($image && empty($branding['category_images'][$product->category_id])) {
                    $branding['category_images'][$product->category_id] = $image;
                    $branding['category_image_preferences'][$product->category_id] = 'auto';
                    $imagesChanged = true;
                }
            }
            ProductMapping::create(['store_id' => $store->id, 'product_id' => $product->id, 'supplier_product_id' => $item->id, 'source' => $item->connection->kind, 'source_key' => 'item:'.$item->id]);
            $count++;
        }

        if ($imagesChanged) {
            $store->update(['branding' => $branding]);
        }

        return $count;
    }

    public function delete(Request $request, Store $store, CatalogProduct $product): RedirectResponse
    {
        abort_unless($product->store_id === $store->id, 404);

        return $this->bulk($request->merge(['action' => 'delete', 'ids' => [$product->id]]), $store);
    }

    public function bulk(Request $request, Store $store): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        CatalogAccess::operational($store);
        $data = $request->validate(['action' => 'required|in:activate,deactivate,delete', 'ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|uuid|distinct']);
        DB::transaction(function () use ($store, $data) {
            $locked = Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            CatalogAccess::operational($locked);
            $products = CatalogProduct::where('store_id', $store->id)->whereIn('id', $data['ids'])->orderBy('id')->lockForUpdate()->get();
            if ($products->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'Pilihan produk tidak valid atau sudah berubah. Muat ulang katalog.']);
            }
            if ($data['action'] !== 'delete') {
                CatalogProduct::where('store_id', $store->id)->whereIn('id', $data['ids'])->update(['active' => $data['action'] === 'activate']);

                return;
            }
            if (PremiumStock::where('store_id', $store->id)->whereIn('product_id', $data['ids'])->whereIn('status', ['reserved', 'sent'])->exists()) {
                throw ValidationException::withMessages(['products' => 'Ada produk dengan stok dipesan/terkirim. Nonaktifkan produk tersebut, atau keluarkan dari pilihan hapus.']);
            }
            if (CustomerOrder::where('store_id', $store->id)->whereIn('product_id', $data['ids'])->whereIn('status', ['awaiting_payment', 'paid', 'processing', 'pending', 'review'])->exists()) {
                throw ValidationException::withMessages(['products' => 'Ada produk yang masih dipakai transaksi berjalan. Nonaktifkan produk tersebut, atau tunggu transaksi selesai.']);
            }
            CatalogProduct::where('store_id', $store->id)->whereIn('id', $data['ids'])->delete();
            $branding = $locked->branding ?? [];
            if (isset($branding['design'])) {
                foreach (['flash_product_ids', 'trending_product_ids'] as $key) {
                    $branding['design'][$key] = array_values(array_diff($branding['design'][$key] ?? [], $data['ids']));
                }
                $branding['design']['flash_discounts'] = array_diff_key($branding['design']['flash_discounts'] ?? [], array_flip($data['ids']));
                $branding['design_revision'] = (int) ($branding['design_revision'] ?? 0) + 1;
                $locked->update(['branding' => $branding]);
            }
        });

        $verb = ['activate' => 'diaktifkan', 'deactivate' => 'dinonaktifkan', 'delete' => 'dihapus'][$data['action']];

        return back()->with('success', count($data['ids']).' produk berhasil '.$verb.'.');
    }

    public function price(Request $request, Store $store, CatalogProduct $product, ProductPricing $pricing, StoreCatalog $catalog): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'pricing.manage');
        CatalogAccess::operational($store);
        abort_unless($product->store_id === $store->id, 404);
        $data = $request->validate(['sale_price' => 'required|integer|min:0|max:1000000000000', 'markup' => 'nullable|string|max:100', 'auto_update' => 'present|nullable|boolean', 'pricing_basis' => 'prohibited']);
        $data['markup'] = $pricing->parse($data['markup'] ?? null)['text'] ?? null;
        $product->update($data);
        $catalog->reprice($store);

        return back()->with('success', 'Harga produk berhasil diperbarui.');
    }

    public function mappings(Request $request, Store $store): JsonResponse
    {
        CatalogAccess::authorize($request, $store, 'products.manage');
        $list = new ListQuery($request, ['kind' => ['ppob', 'premium', 'postpaid']], 10);
        $query = SupplierProduct::with('connection')->where('store_id', $store->id)->whereHas('connection', fn ($q) => $q->where('enabled', true));
        if ($request->filled('connection_id')) {
            $request->validate(['connection_id' => 'uuid']);
            $query->where('connection_id', $request->query('connection_id'));
        }
        if ($request->boolean('inquiry')) {
            $query->where('cost', 0)->where('available', true)->where(fn ($q) => $q->whereNull('billing')->orWhere('billing->adjustment', 0));
        }
        if ($list->filters['kind'] === 'postpaid') {
            $query->where(fn ($q) => $q->where('kind', 'postpaid')->orWhere(fn ($q) => $q->where('kind', 'ppob')->whereHas('connection', fn ($q) => $q->where('kind', 'otomax'))));
        } elseif ($list->filters['kind'] !== '') {
            $query->where('kind', $list->filters['kind']);
        }
        $page = $list->paginate($list->apply($query, ['name', 'sku'])->orderBy('name')->orderBy('id'));

        return response()->json(['items' => $page->getCollection()->map(fn ($item) => [...$item->only(['id', 'name', 'sku', 'mode', 'cost', 'kind', 'billing', 'connection_id']), 'source' => $item->connection->kind, 'allowed' => CatalogAccess::supports($store, $item->connection->kind)]), 'pagination' => ListQuery::meta($page)])->header('Cache-Control', 'private, no-store');
    }

    public function saveRule(Request $request, Store $store, ProductPricing $pricing, StoreCatalog $catalog, ?PricingRule $rule = null): RedirectResponse
    {
        CatalogAccess::authorize($request, $store, 'pricing.manage');
        CatalogAccess::operational($store);
        abort_if($rule && $rule->store_id !== $store->id, 404);
        $request->merge(['key' => mb_strtolower(trim((string) $request->input('key')))]);
        $data = $request->validate(['scope' => 'required|in:category,service', 'key' => ['required', 'string', 'max:100', Rule::unique('pricing_rules')->where('store_id', $store->id)->where('scope', $request->input('scope'))->ignore($rule?->id)],
            'markup' => 'nullable|string|max:100', 'auto_update' => 'present|nullable|boolean', 'pricing_basis' => 'sometimes|nullable|in:cheapest,highest']);
        $target = $data['scope'] === 'service' ? CatalogService::query() : CatalogCategory::query();
        if (! $target->where('store_id', $store->id)->where('name_key', $data['key'])->exists()) {
            $message = $data['scope'] === 'service'
                ? 'Pilih layanan yang terdaftar pada toko ini. Kategori utama harus memakai tingkat aturan Kategori.'
                : 'Pilih kategori utama yang terdaftar pada toko ini. Untuk nama layanan seperti Game, gunakan tingkat aturan Layanan.';
            throw ValidationException::withMessages(['key' => $message]);
        }
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
