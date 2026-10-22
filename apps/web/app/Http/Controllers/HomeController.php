<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CustomerOrder;
use App\Models\Store;
use App\Services\QuickOrderCatalog;
use App\Services\StoreCatalog;
use App\Support\ListQuery;
use App\Support\PlatformHost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, StoreCatalog $catalog): Response|RedirectResponse
    {
        /** @var Store|null $store */
        $store = $request->attributes->get('store');
        if (! $store) {
            return Inertia::render('Platform/Home', [
                'domain' => app(PlatformHost::class)->domain(),
                'previewEnabled' => app()->environment('local') && config('platform.preview_enabled'),
            ]);
        }
        abort_unless($store->supportsWeb(), 404);
        if (in_array($store->template, ['nebula', 'promo'], true) && is_string($request->query('category')) && $request->query('category') !== '') {
            $category = CatalogCategory::where('store_id', $store->id)->where('name', $request->query('category'))
                ->whereHas('products', fn ($query) => $query->where('active', true))->orderBy('id')->first();
            if ($category) {
                return redirect(route('storefront.category', ['category' => $category->id], false));
            }
        }
        $categories = $store->acceptsOrders() ? CatalogProduct::where('store_id', $store->id)->where('active', true)->distinct()->orderBy('category')->limit(500)->pluck('category')->all() : [];
        $list = new ListQuery($request, ['category' => $categories, 'kind' => ['ppob', 'premium', 'postpaid']], 10);
        $query = $catalog->query($store)->where('active', true);
        if (! $store->acceptsOrders() || in_array($store->template, ['nebula', 'promo'], true)) {
            $query->whereRaw('1 = 0');
        }
        $page = $list->paginate($list->apply($query, ['name', 'code', 'category', 'service'], ['category' => 'category', 'kind' => 'kind'])->orderBy('sale_price')->orderBy('id'));
        $categoryPage = null;
        if (in_array($store->template, ['nebula', 'promo'], true)) {
            $categoryQuery = CatalogCategory::where('store_id', $store->id)->with('service:id,name')
                ->whereHas('products', fn ($query) => $query->where('active', true))
                ->withCount(['products' => fn ($query) => $query->where('active', true)]);
            if (! $store->acceptsOrders()) {
                $categoryQuery->whereRaw('1 = 0');
            }
            $categoryPage = $list->paginate($list->apply($categoryQuery, ['name'])->orderBy('name')->orderBy('id'));
        }

        return Inertia::render('Storefront/Home', [
            'store' => $store->only(['id', 'name', 'slug', 'template', 'branding', 'status']),
            'available' => $store->acceptsOrders(), 'products' => $page->getCollection()->map(fn ($product) => $catalog->present($product, $store)), 'preview' => false,
            'categoryNames' => $categories, 'filters' => $list->filters, 'pagination' => ListQuery::meta($page),
            'categoryCards' => $categoryPage?->getCollection()->map(fn ($category) => ['id' => $category->id, 'name' => $category->name,
                'service' => $category->service->name, 'products_count' => $category->products_count,
                'image' => $store->branding['category_images'][$category->id] ?? null]),
            'categoryPagination' => $categoryPage ? ListQuery::meta($categoryPage) : null,
            'renderedAt' => (int) now()->format('Uv'), 'quickOrderProviders' => in_array($store->template, ['aurora', 'minimal'], true) ? app(QuickOrderCatalog::class)->providers() : [],
            'gaming' => $store->acceptsOrders() ? $this->gaming($store, $catalog) : null,
        ]);
    }

    private function gaming(Store $store, StoreCatalog $catalog): array
    {
        $branding = $store->branding ?? [];
        $design = $branding['design'] ?? null;
        $endsAt = $design ? $design['flash_ends_at'] : ($branding['gaming_flash_ends_at'] ?? null);
        $category = $branding['gaming_flash_category'] ?? 'Flash Sale';
        $flash = [];
        if (! $endsAt || now()->lt($endsAt)) {
            $flashQuery = $catalog->query($store, false)->where('active', true);
            if ($design) {
                $flashQuery->whereIn('id', $design['flash_product_ids']);
            } else {
                $flashQuery->where('category', $category);
            }
            $flash = $flashQuery->orderBy('name')->orderBy('id')->limit(12)->get()
                ->sortBy(fn ($product) => $design ? array_search($product->id, $design['flash_product_ids'], true) : $product->name)
                ->map(fn ($product) => $catalog->present($product, $store))
                ->filter(fn ($product) => $product['available'])->values()->all();
        }
        $orders = CustomerOrder::where('store_id', $store->id)->where('is_sandbox', $store->sandbox)->where('purpose', 'product')
            ->where('status', 'success')->where('payment_status', 'paid')->whereNull('refunded_at');
        $popular = (clone $orders)->where('completed_at', '>=', now()->subDays(30))->whereNotNull('product_id')
            ->selectRaw('product_id, COUNT(*) as purchases')->groupBy('product_id')->orderByDesc('purchases')->orderBy('product_id')->limit(24)->get();
        $products = $catalog->query($store, false)->where('active', true)->whereIn('id', $popular->pluck('product_id'))->get()->keyBy('id');
        $trending = $popular->filter(fn ($row) => $products->has($row->product_id))
            ->map(fn ($row) => $catalog->present($products[$row->product_id], $store))
            ->unique('category_id')->take(8)->values()->all();
        if (($design['trending_mode'] ?? null) === 'manual') {
            $trending = $catalog->query($store, false)->where('active', true)->whereIn('id', $design['trending_product_ids'] ?? [])->get()
                ->sortBy(fn ($product) => array_search($product->id, $design['trending_product_ids'], true))
                ->map(fn ($product) => $catalog->present($product, $store))->unique('category_id')->values()->all();
            if (array_key_exists('trending_category_ids', $design) && (! empty($design['trending_category_ids']) || empty($design['trending_product_ids']))) {
                $ids = $design['trending_category_ids'];
                $representatives = CatalogProduct::where('store_id', $store->id)->where('active', true)->whereIn('category_id', $ids)
                    ->selectRaw('MIN(id)')->groupBy('category_id');
                $trending = $catalog->query($store, false)->whereIn('id', $representatives)->get()
                    ->sortBy(fn ($product) => array_search($product->category_id, $ids, true))
                    ->map(fn ($product) => $catalog->present($product, $store))->values()->all();
            }
        }

        return ['flash_sale' => $flash, 'flash_starts_at' => $design['flash_starts_at'] ?? null, 'flash_ends_at' => $endsAt, 'trending' => $trending];
    }
}
