<?php

namespace App\Http\Controllers;

use App\Models\PlanOption;
use App\Models\SubscriptionInvoice;
use App\Support\ListQuery;
use App\Support\PackageCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->role === 'admin', 403);

        $list = new ListQuery($request, ['category' => ['bot', 'web', 'full'], 'plan' => ['orbit', 'space', 'galaxy'], 'package' => PackageCatalog::keys(), 'active' => ['0', '1']], 10);
        $page = $list->paginate($list->apply(PackageCatalog::filter(PackageCatalog::ordered(PlanOption::query()), (string) ($list->filters['package'] ?? '')), ['name'], ['category' => 'category', 'plan' => 'plan', 'active' => 'active'])->orderBy('duration_days')->orderBy('price')->orderBy('id'));

        return Inertia::render('Admin/Plans', ['plans' => $page->items(), 'pagination' => ListQuery::meta($page), 'filters' => $list->filters]);
    }

    public function save(Request $request, ?PlanOption $plan = null): RedirectResponse
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $data = $request->validate(['name' => 'required|string|max:100', 'category' => 'required|in:bot,web,full', 'plan' => 'required|in:orbit,space,galaxy',
            'price' => 'required|integer|min:1000|max:100000000', 'duration_days' => 'required|integer|min:1|max:730', 'active' => 'required|boolean']);
        if ($plan) {
            $plan->update($data);
        } else {
            PlanOption::create($data);
        }

        return back()->with('success', 'Paket tersimpan. Invoice dan masa aktif existing tidak berubah.');
    }

    public function invoices(Request $request): Response
    {
        abort_unless($request->user()?->role === 'admin', 403);

        $list = new ListQuery($request, ['status' => ['pending', 'paid', 'applied', 'expired', 'review'], 'purpose' => ['initial', 'renew', 'change', 'downgrade']]);
        $page = $list->paginate($list->apply(SubscriptionInvoice::query(), ['id', 'store_id', 'snapshot->name'], ['status' => 'status', 'purpose' => 'purpose'])->latest()->orderByDesc('id'));

        return Inertia::render('Admin/Billing', ['invoices' => $page, 'filters' => $list->filters]);
    }
}
