<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceWindow;
use App\Models\Store;
use App\Services\MailBranding;
use App\Services\StoreMaintenance;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MaintenanceController extends Controller
{
    private function authorize(Request $request, ?Store $store): void
    {
        $owner = $request->user('web');
        abort_unless($owner && ($store ? $owner->role === 'owner' && $store->owner_id === $owner->id : $owner->role === 'admin'), 403);
    }

    public function show(Request $request, StoreMaintenance $maintenance, ?Store $store = null): Response
    {
        $this->authorize($request, $store);

        return Inertia::render('Maintenance/Settings', ['role' => $store ? 'owner' : 'admin',
            'store' => $store?->only(['id', 'name']), 'settings' => $maintenance->settings($store),
            'platformMaintenance' => $store ? $maintenance->active() : null, 'initialNow' => now()->toIso8601String()]);
    }

    public function save(Request $request, StoreMaintenance $maintenance, ?Store $store = null): RedirectResponse
    {
        $this->authorize($request, $store);
        $data = $request->validate(['enabled' => 'required|boolean', 'message' => 'nullable|string|max:1000',
            'ends_at' => ['nullable', 'date', ...($request->boolean('enabled') ? ['after:now'] : [])]]);
        DB::transaction(function () use ($request, $maintenance, $store, $data): void {
            if ($store) {
                Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            }
            MaintenanceWindow::updateOrCreate(['scope' => $maintenance->scope($store)], [
                'store_id' => $store?->id, 'enabled' => $data['enabled'], 'message' => $data['message'] ?? null,
                'ends_at' => $data['enabled'] && filled($data['ends_at'] ?? null) ? CarbonImmutable::parse($data['ends_at'])->utc() : null,
                'updated_by' => $request->user('web')->id,
            ]);
        });

        return back()->with('success', $data['enabled'] ? 'Maintenance diaktifkan. Transaksi yang sudah berjalan tetap diperbarui.' : 'Maintenance dinonaktifkan.');
    }

    public function notice(Request $request, StoreMaintenance $maintenance): \Symfony\Component\HttpFoundation\Response
    {
        $store = $request->attributes->get('store');
        abort_unless($store instanceof Store, 404);
        $active = $maintenance->active($store);
        if (! $active) {
            return redirect('/');
        }

        return response()->view('store-maintenance', ['maintenance' => $active, 'brand' => app(MailBranding::class)->store($store), 'serverNow' => now()->timestamp])
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
