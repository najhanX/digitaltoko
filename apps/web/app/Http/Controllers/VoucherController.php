<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\CatalogProduct;
use App\Models\CatalogService;
use App\Models\CustomerInquiry;
use App\Models\DiscountVoucher;
use App\Models\PlanOption;
use App\Models\Store;
use App\Services\CustomerAccess;
use App\Services\StoreCatalog;
use App\Services\Vouchers;
use App\Support\CatalogAccess;
use App\Support\HanshopIdentity;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class VoucherController extends Controller
{
    private function access(Request $request, ?Store $store, bool $manage = false): string
    {
        if (! $store) {
            abort_unless($request->user('web')?->role === 'admin', 403);

            return 'admin';
        }
        $role = CatalogAccess::authorize($request, $store, $manage ? 'vouchers.manage' : 'vouchers.view');
        if ($manage) {
            CatalogAccess::operational($store);
        }

        return $role;
    }

    public function index(Request $request, ?Store $store = null): Response
    {
        $role = $this->access($request, $store);
        $list = new ListQuery($request, ['active' => ['1', '0']], 10);
        $query = DiscountVoucher::where('scope_key', $store?->id ?? 'platform');
        $page = $list->paginate($list->apply($query, ['code', 'name'], ['active' => 'active'])->latest());
        $items = $page->getCollection()->map(fn ($v) => [...$v->toArray(), 'used' => app(Vouchers::class)->used($v, (bool) $store?->sandbox), 'selected' => $this->selected($v, $store)]);

        return Inertia::render('Owner/Vouchers', ['role' => $role, 'store' => $store?->only(['id', 'name', 'sandbox']), 'items' => $items, 'filters' => $list->filters, 'pagination' => ListQuery::meta($page), 'canManage' => $role === 'admin' || CatalogAccess::has($request, $role, 'vouchers.manage')]);
    }

    private function optionsQuery(string $type, ?Store $store): Builder
    {
        $model = match ($type) {
            'services' => CatalogService::class,'categories' => CatalogCategory::class,'products' => CatalogProduct::class,'plans' => PlanOption::class
        };

        $fields = match ($type) {
            'plans' => ['id', 'name', 'duration_days', 'price'],'categories' => ['id', 'name', 'service_id'],'products' => ['id', 'name', 'code'],default => ['id', 'name']
        };

        return $model::query()->when($type === 'plans', fn ($q) => $q->where('plan', 'galaxy'))->when($store, fn ($q) => $q->where('store_id', $store->id))->when($type === 'categories', fn ($q) => $q->with('service:id,name'))->select($fields);
    }

    private function option(Model $item, string $type): array
    {
        $suffix = match ($type) {
            'plans' => (str_contains(mb_strtolower($item->name), $item->duration_days.' hari') ? '' : $item->duration_days.' hari · ').'Rp'.number_format($item->price, 0, ',', '.'),'categories' => $item->service?->name,'products' => $item->code,default => null
        };

        return ['id' => (string) $item->id, 'name' => $item->name.($suffix ? ' · '.$suffix : '')];
    }

    private function selected(DiscountVoucher $v, ?Store $store): array
    {
        $result = [];
        foreach (($store ? ['services', 'categories', 'products'] : ['plans']) as $type) {
            $result[$type] = $this->optionsQuery($type, $store)->whereIn('id', $v->targets[$type] ?? [])->get()->map(fn ($item) => $this->option($item, $type))->toArray();
        }

        return $result;
    }

    public function options(Request $request, ?Store $store = null): JsonResponse
    {
        $this->access($request, $store);
        $data = $request->validate(['type' => 'required|in:'.($store ? 'services,categories,products' : 'plans')]);
        $list = new ListQuery($request, [], 10);

        $result = $list->options($list->apply($this->optionsQuery($data['type'], $store), ['name'])->orderBy('name')->orderBy('id'));
        $result['items'] = $result['items']->map(fn ($item) => $this->option($item, $data['type']));

        return response()->json($result);
    }

    public function save(Request $request, ?Store $store = null, ?DiscountVoucher $voucher = null): RedirectResponse
    {
        $this->access($request, $store, true);
        $scope = $store?->id ?? 'platform';
        abort_if($voucher && $voucher->scope_key !== $scope, 404);
        $request->merge(['code' => Vouchers::code($request->input('code'))]);
        $data = $request->validate(['code' => 'required|string|min:3|max:40|regex:/^[A-Z0-9_-]+$/', 'name' => 'required|string|max:120', 'active' => 'required|boolean', 'all_targets' => 'required|boolean', 'type' => 'required|in:fixed,percent', 'value' => 'required|numeric|min:0.01|max:100000000', 'min_purchase' => 'required|integer|min:0|max:100000000', 'max_discount' => 'nullable|integer|min:1|max:100000000', 'usage_limit' => 'nullable|integer|min:1|max:1000000', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date', 'targets' => 'required|array:services,categories,products,plans', 'targets.services' => 'present|array|max:300', 'targets.categories' => 'present|array|max:300', 'targets.products' => 'present|array|max:300', 'targets.plans' => 'present|array|max:300', 'targets.*.*' => 'required|string|max:40']);
        if (! empty($data['starts_at']) && ! empty($data['ends_at']) && strtotime($data['ends_at']) <= strtotime($data['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => 'Tanggal akhir harus setelah tanggal mulai.']);
        }
        if ($data['type'] === 'percent' && $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'Persentase maksimal 100%.']);
        }
        if ($data['type'] === 'fixed' && floor($data['value']) != $data['value']) {
            throw ValidationException::withMessages(['value' => 'Potongan rupiah harus berupa bilangan bulat.']);
        }
        foreach (['services', 'categories', 'products', 'plans'] as $type) {
            $ids = array_values(array_unique($data['targets'][$type]));
            $data['targets'][$type] = $ids;
            if (! $ids) {
                continue;
            }
            if (! $store && $type === 'services') {
                abort_unless(count(array_diff($ids, ['bot', 'web', 'full', 'custom_qris'])) === 0, 422);

                continue;
            }
            abort_unless(in_array($type, $store ? ['services', 'categories', 'products'] : ['plans'], true), 422);
            abort_unless($this->optionsQuery($type, $store)->whereIn('id', $ids)->count() === count($ids), 422);
        }
        if (! $data['all_targets'] && ! array_filter($data['targets'])) {
            throw ValidationException::withMessages(['targets' => 'Pilih setidaknya satu sasaran voucher.']);
        }
        $data['value'] = $data['type'] === 'percent' ? (int) round($data['value'] * 100) : (int) $data['value'];
        DB::transaction(function () use ($data, $voucher, $store, $scope) {
            if ($voucher) {
                $voucher = DiscountVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            }
            if (DiscountVoucher::withTrashed()->where('scope_key', $scope)->where('code', $data['code'])->when($voucher, fn ($q) => $q->where('id', '!=', $voucher->id))->exists()) {
                throw ValidationException::withMessages(['code' => 'Kode sudah pernah digunakan. Gunakan kode lain.']);
            }
            if ($voucher && $voucher->code !== $data['code'] && DB::table('voucher_redemptions')->where('voucher_id', $voucher->id)->exists()) {
                throw ValidationException::withMessages(['code' => 'Kode yang sudah dipakai tidak dapat diubah.']);
            }
            ($voucher ?? new DiscountVoucher)->fill([...$data, 'store_id' => $store?->id, 'scope_key' => $scope])->save();
        });

        return back()->with('success', 'Voucher disimpan.');
    }

    public function delete(Request $request, ?Store $store = null, ?DiscountVoucher $voucher = null): RedirectResponse
    {
        $this->access($request, $store, true);
        abort_unless($voucher && $voucher->scope_key === ($store?->id ?? 'platform'), 404);
        DB::transaction(function () use ($voucher) {
            $v = DiscountVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            $v->update(['active' => false]);
            $v->delete();
        });

        return back()->with('success', 'Voucher dihapus. Invoice yang sudah dibuat tetap berlaku.');
    }

    public function preview(Request $request, CustomerAccess $access, Vouchers $vouchers): JsonResponse
    {
        $store = $access->store($request);
        abort_unless($store->supportsWeb() && $store->acceptsOrders(), 404);
        $data = $request->validate(['product_id' => 'required|uuid', 'voucher_code' => 'required|string|max:40', 'inquiry_id' => 'nullable|uuid', 'phone' => 'nullable|string|max:30']);
        $product = app(StoreCatalog::class)->query($store)->whereKey($data['product_id'])->firstOrFail();
        $quote = app(StoreCatalog::class)->present($product, $store, true);
        abort_unless($quote['available'], 422);
        $amount = $quote['price'];
        if ($product->kind === 'postpaid') {
            $inquiry = CustomerInquiry::where('store_id', $store->id)->where('product_id', $product->id)->whereKey($data['inquiry_id'] ?? '')->where('phone', HanshopIdentity::phone($data['phone'] ?? ''))->where('expires_at', '>', now())->firstOrFail();
            abort_unless($inquiry->status === 'ready' && (bool) ($inquiry->data['sandbox'] ?? false) === $store->sandbox, 422);
            $amount = $inquiry->amount;
        }

        return response()->json(['voucher' => $vouchers->quote($store, $data['voucher_code'], $amount, $product)])->header('Cache-Control', 'private, no-store');
    }
}
