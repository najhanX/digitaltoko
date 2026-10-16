<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\CustomerOrder;
use App\Services\CustomerAccess;
use App\Services\CustomerCommerce;
use App\Services\GameIdentity;
use App\Services\StoreCatalog;
use App\Support\ListQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StorefrontPageController extends Controller
{
    public function category(Request $request, CatalogCategory $category, CustomerAccess $access, StoreCatalog $catalog): Response
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb() && $store->acceptsOrders() && $category->store_id === $store->id, 404);
        $list = new ListQuery($request, [], 20);
        $query = $catalog->query($store)->where('active', true)->where('category_id', $category->id);
        abort_unless((clone $query)->exists(), 404);
        $page = $list->paginate($list->apply(clone $query, ['name', 'code'])->orderBy('sale_price')->orderBy('id'));
        $selected = is_string($request->query('product')) ? (clone $query)->find($request->query('product')) : null;
        $setting = app(CustomerCommerce::class)->settings($store);
        Inertia::encryptHistory();

        return Inertia::render('Storefront/Category', [
            'store' => $store->only(['name', 'slug', 'template', 'branding']),
            'category' => ['id' => $category->id, 'name' => $category->name, 'service' => $category->service->name,
                'image' => $store->branding['category_images'][$category->id] ?? null],
            'products' => $page->getCollection()->map(fn ($product) => $catalog->present($product, $store)),
            'selectedProduct' => $selected ? $catalog->present($selected, $store) : null,
            'gameProfile' => app(GameIdentity::class)->profile($store, $category),
            'filters' => $list->filters, 'pagination' => ListQuery::meta($page),
            'customer' => $access->customer($request, $store)?->only(['name', 'phone', 'email', 'balance']),
            'allowGuest' => $setting->allow_guest, 'gateway' => $setting->gateway,
            'feeMarkup' => $setting->fee_payer === 'customer' ? $setting->fee_markup : '0',
        ]);
    }

    public function leaderboard(Request $request, CustomerAccess $access): Response
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb(), 404);
        $period = in_array($request->query('period'), ['day', 'week', 'month'], true) ? $request->query('period') : 'month';
        $start = match ($period) {
            'day' => now()->timezone('Asia/Jakarta')->startOfDay()->utc(),
            'week' => now()->timezone('Asia/Jakarta')->startOfWeek()->utc(),
            default => now()->timezone('Asia/Jakarta')->startOfMonth()->utc(),
        };
        $list = new ListQuery($request, [], 10);
        $query = CustomerOrder::where('store_id', $store->id)->where('purpose', 'product')->where('status', 'success')
            ->where('payment_status', 'paid')->whereNull('refunded_at')->whereNotNull('customer_id')
            ->where('completed_at', '>=', $start)->where('completed_at', '<=', now())
            ->selectRaw('customer_id, SUM(amount) as spend, COUNT(*) as purchases')->groupBy('customer_id')
            ->orderByDesc('spend')->orderBy('customer_id');
        if (! $store->acceptsOrders()) {
            $query->whereRaw('1 = 0');
        }
        $page = $list->paginate($query);

        return Inertia::render('Storefront/Leaderboard', ['store' => $store->only(['name', 'slug', 'template', 'branding']),
            'period' => $period, 'pagination' => ListQuery::meta($page), 'filters' => $list->filters,
            'ranking' => $page->getCollection()->map(fn ($row) => [
                'name' => 'Gamer '.strtoupper(substr(hash_hmac('sha256', $store->id.':'.$row->customer_id, (string) config('app.key')), 0, 4)).'••',
                'amount' => (int) $row->spend, 'purchases' => (int) $row->purchases,
            ]),
        ]);
    }

    public function calculator(Request $request, CustomerAccess $access, ?string $calculator = null): Response
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb(), 404);

        return Inertia::render('Storefront/Calculator', ['store' => $store->only(['name', 'slug', 'template', 'branding']), 'calculator' => $calculator]);
    }
}
