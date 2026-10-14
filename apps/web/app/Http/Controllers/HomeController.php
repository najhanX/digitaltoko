<?php

namespace App\Http\Controllers;

use App\Models\CatalogProduct;
use App\Models\Store;
use App\Services\StoreCatalog;
use App\Support\ListQuery;
use App\Support\PlatformHost;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, StoreCatalog $catalog): Response
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
        $categories = $store->acceptsOrders() ? CatalogProduct::where('store_id', $store->id)->where('active', true)->distinct()->orderBy('category')->limit(500)->pluck('category')->all() : [];
        $list = new ListQuery($request, ['category' => $categories, 'kind' => ['ppob', 'premium', 'postpaid']], 10);
        $query = $catalog->query($store)->where('active', true);
        if (! $store->acceptsOrders()) {
            $query->whereRaw('1 = 0');
        }
        $page = $list->paginate($list->apply($query, ['name', 'code', 'category', 'service'], ['category' => 'category', 'kind' => 'kind'])->orderBy('name')->orderBy('id'));

        return Inertia::render('Storefront/Home', [
            'store' => $store->only(['id', 'name', 'slug', 'template', 'branding', 'status']),
            'available' => $store->acceptsOrders(), 'products' => $page->getCollection()->map(fn ($product) => $catalog->present($product, $store)), 'preview' => false,
            'categoryNames' => $categories, 'filters' => $list->filters, 'pagination' => ListQuery::meta($page),
        ]);
    }
}
