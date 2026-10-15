<?php

namespace App\Http\Controllers;

use App\Models\CatalogProduct;
use App\Models\Store;
use App\Services\StoreCatalog;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotCatalogController extends Controller
{
    private function authorizedStore(Request $request): Store
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401);
        $store = Store::where('worker_token_hash', hash('sha256', $token))->first();
        abort_unless($store, 401);
        abort_unless(in_array($store->category, ['bot', 'full'], true) && $store->acceptsOrders(), 403);

        return $store;
    }

    private function scope(Builder $query, string $column, ?string $value): Builder
    {
        return $value !== null ? $query->whereRaw('LOWER('.$column.') = ?', [mb_strtolower($value)]) : $query;
    }

    public function browse(Request $request, StoreCatalog $catalog): JsonResponse
    {
        $store = $this->authorizedStore($request);
        $data = $request->validate(['view' => 'required|in:services,categories,products', 'service' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100']);
        $view = $data['view'];
        $service = $data['service'] ?? null;
        $category = $data['category'] ?? null;
        $query = CatalogProduct::where('store_id', $store->id)->where('active', true);
        $list = new ListQuery($request, [], 10);
        if ($view === 'services') {
            $page = $list->paginate($query->selectRaw('MIN(service) as name, COUNT(*) as product_count, COUNT(DISTINCT LOWER(category)) as category_count')->groupByRaw('LOWER(service)')->orderByRaw('LOWER(service)'));
            $items = $page->getCollection()->map(fn ($row) => ['name' => $row->name, 'product_count' => (int) $row->product_count, 'category_count' => (int) $row->category_count]);
        } else {
            abort_unless($view === 'categories' ? $service !== null : $category !== null, 422);
            $this->scope($query, 'service', $service);
            if ($view === 'products') {
                $this->scope($query, 'category', $category);
                if ($service === null) {
                    $services = (clone $query)->selectRaw('MIN(service) as name')->groupByRaw('LOWER(service)')->limit(2)->get();
                    if ($services->count() > 1) {
                        $view = 'categories';
                    } else {
                        $service = $services->first()?->name;
                    }
                }
            }
            if ($view === 'categories') {
                $page = $list->paginate($query->selectRaw('MIN(category) as name, MIN(service) as service_name, COUNT(*) as product_count')->groupByRaw('LOWER(service), LOWER(category)')->orderByRaw('LOWER(service), LOWER(category)'));
                $items = $page->getCollection()->map(fn ($row) => ['name' => $row->name, 'service' => $row->service_name, 'product_count' => (int) $row->product_count]);
            } else {
                $products = $this->scope($catalog->query($store)->where('active', true), 'category', $category);
                $this->scope($products, 'service', $service);
                $page = $list->paginate($products->orderBy('name')->orderBy('id'));
                $items = $page->getCollection()->map(fn ($product) => $catalog->present($product, $store));
            }
        }

        return response()->json(['status' => 'SUCCESS', 'data' => ['view' => $view, 'store_name' => $store->name,
            'service' => $service, 'category' => $category, 'items' => $items, 'pagination' => ListQuery::meta($page)]])->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(Request $request, StoreCatalog $catalog): JsonResponse
    {
        $store = $this->authorizedStore($request);
        $list = new ListQuery($request, ['kind' => ['ppob', 'premium', 'postpaid']], 10);
        $page = $list->paginate($list->apply($catalog->query($store)->where('active', true), ['name', 'code', 'category', 'service'], ['kind' => 'kind'])->orderBy('category')->orderBy('name')->orderBy('id'));

        return response()->json(['status' => 'SUCCESS', 'data' => ['store_name' => $store->name, 'items' => $page->getCollection()->map(fn ($product) => $catalog->present($product, $store)),
            'pagination' => ListQuery::meta($page)]])->header('Cache-Control', 'private, no-store');
    }
}
