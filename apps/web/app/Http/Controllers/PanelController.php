<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\ListQuery;
use App\Support\PackageCatalog;
use App\Support\PlatformHost;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PanelController extends Controller
{
    private function storeData(Store $store, ?string $billingStatus = null): array
    {
        return [...$store->only(['id', 'name', 'slug', 'category', 'plan', 'status', 'template', 'expires_at', 'is_trial', 'starts_at']), 'billing_status' => $billingStatus,
            'url' => app(PlatformHost::class)->storeUrl($store)];
    }

    private function summaries(Collection $stores): Collection
    {
        $service = app(SubscriptionService::class);
        foreach ($stores as $store) {
            if (in_array($store->status, ['active', 'grace', 'expired', 'provisioning'], true)) {
                $service->tick($store);
                $store->refresh();
            }
        }
        $billing = SubscriptionInvoice::whereIn('store_id', $stores->pluck('id'))->whereIn('status', ['pending', 'review'])->pluck('status', 'store_id');

        return $stores->map(fn ($store) => $this->storeData($store, $billing[$store->id] ?? null));
    }

    public function admin(Request $request)
    {
        abort_unless($request->user()?->role === 'admin', 403);

        $list = $this->storeList($request);
        $page = $list->paginate($list->apply(PackageCatalog::filter(Store::with('domain'), (string) ($list->filters['package'] ?? '')), ['name', 'slug'], ['status' => 'status', 'category' => 'category', 'plan' => 'plan'])->latest()->orderByDesc('id'));

        return Inertia::render('Panel/Dashboard', ['role' => 'admin', 'preview' => false,
            'viewer' => $request->user()->only(['name']),
            'stores' => $page->getCollection()->map(fn ($s) => $this->storeData($s)), 'pagination' => ListQuery::meta($page), 'filters' => $list->filters,
            'counts' => ['stores' => Store::count(), 'owners' => User::where('role', 'owner')->count(), 'setup' => Store::whereIn('status', ['draft', 'provisioning'])->count()],
        ]);
    }

    public function owner(Request $request)
    {
        abort_unless($request->user()?->role === 'owner', 403);

        return $this->ownerList($request, false);
    }

    public function stores(Request $request)
    {
        abort_unless($request->user()?->role === 'owner', 403);

        return $this->ownerList($request, true);
    }

    private function storeList(Request $request): ListQuery
    {
        return new ListQuery($request, ['status' => ['draft', 'provisioning', 'active', 'grace', 'expired', 'suspended', 'suspended_manual', 'purging'],
            'category' => ['bot', 'web', 'full'], 'plan' => ['orbit', 'space', 'galaxy'], 'package' => PackageCatalog::keys()], 10);
    }

    private function ownerList(Request $request, bool $listing): Response
    {
        $list = $this->storeList($request);
        $query = Store::where('owner_id', $request->user()->id);
        $page = $list->paginate($list->apply(PackageCatalog::filter((clone $query)->with('domain'), (string) ($list->filters['package'] ?? '')), ['name', 'slug'], ['status' => 'status', 'category' => 'category', 'plan' => 'plan'])->latest()->orderByDesc('id'));
        $stores = $this->summaries($page->getCollection());
        $statuses = (clone $query)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Panel/Dashboard', ['role' => 'owner', 'preview' => false, 'listing' => $listing,
            'viewer' => $request->user()->only(['name']), 'ownerStatus' => $request->user()->verification_status,
            'counts' => ['stores' => array_sum($statuses->all()), 'active' => (int) ($statuses['active'] ?? 0),
                'setup' => (int) ($statuses['draft'] ?? 0) + (int) ($statuses['provisioning'] ?? 0),
                'subscribed' => (clone $query)->where(fn ($nested) => $nested->whereNotNull('expires_at')->orWhere('status', 'provisioning'))->exists() ? 1 : 0],
            'stores' => $stores, 'pagination' => ListQuery::meta($page), 'filters' => $list->filters]);
    }

    public function store(Request $request, Store $store)
    {
        abort_unless($request->user()?->role === 'owner' && $store->owner_id === $request->user()->id, 403);

        return Inertia::render('Panel/Dashboard', ['role' => 'owner', 'preview' => false,
            'viewer' => $request->user()->only(['name']), 'counts' => [], 'ownerStatus' => $request->user()->verification_status, 'storeScope' => true, 'stores' => $this->summaries(collect([$store]))]);
    }

    public function staff(Request $request, Store $store)
    {
        $staff = $request->user('staff');
        abort_unless($staff && $staff->active && $staff->store_id === $store->id, 403);
        abort_unless(in_array('dashboard.view', $staff->permissions ?? [], true), 403);

        return Inertia::render('Panel/Dashboard', ['role' => 'staff', 'preview' => false,
            'viewer' => ['name' => $staff->name], 'counts' => [],
            'permissions' => $staff->permissions ?? [], 'storeScope' => true, 'stores' => $this->summaries(collect([$store]))]);
    }

    public function staffAccess(Request $request, Store $store)
    {
        $staff = $request->user('staff');
        abort_unless($staff && $staff->active && $staff->store_id === $store->id, 403);

        return Inertia::render('Panel/Access', ['storeName' => $store->name]);
    }
}
