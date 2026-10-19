<?php

namespace App\Http\Controllers;

use App\Models\CatalogProduct;
use App\Models\Store;
use App\Models\StorePaymentSetting;
use App\Services\CategoryDestination;
use App\Services\CustomerAccess;
use App\Services\QuickOrderCatalog;
use App\Services\StoreCatalog;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuickOrderController extends Controller
{
    private function store(Request $request, CustomerAccess $access): Store
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb() && $store->acceptsOrders() && in_array($store->template, ['aurora', 'minimal'], true), 404);
        abort_if(StorePaymentSetting::where('store_id', $store->id)->value('closing'), 404);

        return $store;
    }

    public function products(Request $request, CustomerAccess $access, QuickOrderCatalog $quick, StoreCatalog $catalog): JsonResponse
    {
        $store = $this->store($request, $access);
        $data = $request->validate(['provider' => ['required', Rule::in(array_keys(config('mobile_providers')))], 'service' => ['required', Rule::in(QuickOrderCatalog::SERVICES)]]);
        $list = new ListQuery($request, [], 5);
        $query = $catalog->query($store)->where('active', true)->where('kind', 'ppob')->whereIn('category_id', $quick->categoryIds($store, $data['provider'], $data['service']));
        $page = $list->paginate($list->apply($query, ['name', 'code'])->orderBy('sale_price')->orderBy('id'));

        return response()->json(['items' => $page->getCollection()->map(fn ($product) => $catalog->present($product, $store)), 'pagination' => ListQuery::meta($page)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function checkout(Request $request, CustomerAccess $access, QuickOrderCatalog $quick): RedirectResponse
    {
        $store = $this->store($request, $access);
        $data = $request->validate(['product_id' => 'required|uuid', 'destination' => ['required', 'string', 'regex:/^08[0-9]{8,13}$/']]);
        $product = CatalogProduct::with('taxonomyCategory.service')->where('store_id', $store->id)->where('active', true)->where('kind', 'ppob')->findOrFail($data['product_id']);
        abort_unless($quick->category($store, $product->taxonomyCategory), 422, 'Produk ini tidak tersedia untuk quick order.');
        $destination = app(CategoryDestination::class)->destination($store, $product, $data, $data['destination']);
        $request->session()->flash('quick_order', ['store_id' => $store->id, 'product_id' => $product->id, 'destination' => $destination]);

        return redirect('/checkout?product='.$product->id, 303)->header('Cache-Control', 'private, no-store');
    }
}
