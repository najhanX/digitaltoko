<?php

namespace App\Http\Controllers;

use App\Models\PlanOption;
use App\Models\Store;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionService;
use App\Support\BotConnection;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    private function access(Request $request, Store $store, bool $write = false): string
    {
        $owner = $request->user('web');
        $staff = $request->user('staff');
        if ($owner?->role === 'owner' && $owner->id === $store->owner_id) {
            abort_unless($owner->verification_status === 'approved', 403);

            return 'owner';
        }
        abort_unless($staff && $staff->active && $staff->store_id === $store->id && in_array($write ? 'subscription.renew' : 'subscription.view', $staff->permissions ?? [], true), 403);

        return 'staff';
    }

    public function show(Request $request, Store $store, SubscriptionService $service): Response
    {
        $role = $this->access($request, $store);
        $service->tick($store);
        $store->refresh();

        $current = SubscriptionInvoice::where('store_id', $store->id)->whereIn('status', ['pending', 'paid', 'review'])->latest()->orderByDesc('id')->first();
        $list = new ListQuery($request, ['status' => ['pending', 'paid', 'applied', 'expired', 'review']], 10, 'history_');
        $history = SubscriptionInvoice::where('store_id', $store->id)->when($current, fn ($query) => $query->where('id', '!=', $current->id));
        $page = $list->paginate($list->apply($history, ['id', 'snapshot->name'], ['status' => 'status'])->latest()->orderByDesc('id'));

        return Inertia::render('Owner/Subscription', ['role' => $role, 'store' => $store->only(['id', 'name', 'slug', 'category', 'plan', 'status', 'expires_at', 'ready_at', 'is_trial']),
            'plans' => PlanOption::where('active', true)->orderBy('duration_days')->orderBy('price')->get()
                ->map(fn ($plan) => [...$plan->toArray(), 'purpose' => $service->purpose($store, $plan)]),
            'invoices' => $page->items(), 'currentInvoice' => $current, 'historyPagination' => ListQuery::meta($page), 'historyFilters' => $list->filters,
            'ready' => $service->ready($store), 'trialAvailable' => ! $store->owner->trial_used_at && (! $store->owner->trial_store_id || $store->owner->trial_store_id === $store->id),
            'canManage' => $role === 'owner' || (! $store->is_trial && $store->expires_at && in_array('subscription.renew', $request->user('staff')->permissions ?? [], true)),
            'canManageBot' => $role === 'owner' || in_array('bot.manage', $request->user('staff')->permissions ?? [], true),
            'botStatus' => BotConnection::state($store, false)['status'] ?? 'DISCONNECTED']);
    }

    public function checkout(Request $request, Store $store, SubscriptionService $service): RedirectResponse
    {
        $role = $this->access($request, $store, true);
        $data = $request->validate(['plan_id' => 'required|integer|exists:plan_options,id']);
        $plan = PlanOption::findOrFail($data['plan_id']);
        if ($role === 'staff') {
            abort_unless(! $store->is_trial && $store->expires_at && $store->category === $plan->category && $store->plan === $plan->plan, 403);
        }
        $service->checkout($store, $plan);

        return back()->with('success', 'Invoice disiapkan. Bayar sesuai total QRIS dan cek status pembayaran.');
    }

    public function refresh(Request $request, Store $store, SubscriptionInvoice $invoice, SubscriptionService $service): RedirectResponse
    {
        $this->access($request, $store);
        abort_unless($invoice->store_id === $store->id, 404);
        $service->sync($invoice);

        return back()->with('success', 'Status pembayaran diperbarui.');
    }

    public function trial(Request $request, Store $store, SubscriptionService $service): RedirectResponse
    {
        abort_unless($this->access($request, $store, true) === 'owner', 403);
        $service->requestTrial($store);

        return back()->with('success', 'Trial disiapkan. Hubungkan bot sebelum memulai masa aktif.');
    }

    public function activate(Request $request, Store $store, SubscriptionService $service): RedirectResponse
    {
        abort_unless($this->access($request, $store, true) === 'owner', 403);
        $service->activate($store);

        return back()->with('success', 'Toko aktif. Masa sewa mulai dihitung.');
    }
}
