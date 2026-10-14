<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\StoreCatalog;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotCatalogController extends Controller
{
    public function __invoke(Request $request, StoreCatalog $catalog): JsonResponse
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && strlen($token) >= 32, 401);
        $store = Store::where('worker_token_hash', hash('sha256', $token))->first();
        abort_unless($store, 401);
        abort_unless(in_array($store->category, ['bot', 'full'], true) && $store->acceptsOrders(), 403);
        $list = new ListQuery($request, ['kind' => ['ppob', 'premium', 'postpaid']], 10);
        $page = $list->paginate($list->apply($catalog->query($store)->where('active', true), ['name', 'code', 'category', 'service'], ['kind' => 'kind'])->orderBy('category')->orderBy('name')->orderBy('id'));

        return response()->json(['status' => 'SUCCESS', 'data' => ['store_name' => $store->name, 'items' => $page->getCollection()->map(fn ($product) => $catalog->present($product, $store)),
            'pagination' => ListQuery::meta($page)]])->header('Cache-Control', 'private, no-store');
    }
}
