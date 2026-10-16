<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Services\CustomerAccess;
use App\Services\GameIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameIdentityController extends Controller
{
    public function __invoke(Request $request, CustomerAccess $access, GameIdentity $identity): JsonResponse
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb() && $store->acceptsOrders(), 404);
        $input = $request->validate(['category_id' => 'required|uuid', 'id' => 'required|string|max:64', 'server' => 'nullable|string|max:64']);
        $category = CatalogCategory::where('store_id', $store->id)->whereHas('products', fn ($query) => $query->where('active', true))->findOrFail($input['category_id']);

        return response()->json(['data' => $identity->lookup($store, $category, $input)])->header('Cache-Control', 'private, no-store');
    }
}
