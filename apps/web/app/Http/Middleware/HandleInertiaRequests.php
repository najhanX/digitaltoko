<?php

namespace App\Http\Middleware;

use App\Models\CustomerOrder;
use App\Services\CustomerAccess;
use App\Services\CustomerCommerce;
use App\Services\PanelNotifications;
use App\Services\StoreMaintenance;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'brand' => ['name' => 'DigitalToko'], 'ownerIdentityRequired' => (bool) config('platform.owner_ktp_required'),
            'storeMaintenance' => function () use ($request) {
                $store = $request->attributes->get('store');
                $maintenance = $store ? app(StoreMaintenance::class)->active($store) : null;
                if (! $maintenance) {
                    return null;
                }

                return [...$maintenance, 'server_now' => now()->toIso8601String(), 'store_name' => $store->name, 'store_slug' => $store->slug, 'template' => $store->template,
                    'branding' => ['design' => array_intersect_key($store->branding['design'] ?? [], array_flip(['accent', 'colors', 'logo']))]];
            },
            'ownerEmailGate' => function () use ($request) {
                $owner = $request->user('web');
                if ($owner?->role !== 'owner' || (app(WorkspaceContext::class)->forRequest($request)['role'] ?? null) !== 'owner' || ! $request->attributes->get('platform_host') || config('backup.preview')) {
                    return null;
                }

                return ['required' => $owner->requiresEmailVerification(),
                    'email' => str_ends_with($owner->email, '@identity.invalid') ? '' : $owner->email,
                    'pending' => (bool) $request->session()->get('owner_challenge_email')];
            },
            'storeCustomer' => function () use ($request) {
                $store = $request->attributes->get('store');

                $customer = $store ? app(CustomerAccess::class)->customer($request, $store) : null;
                if (! $customer) {
                    return null;
                }

                return [...$customer->only(['id', 'name']),
                    'balance' => $store->sandbox ? $customer->sandbox_balance : $customer->balance,
                    'can_topup' => $store->acceptsOrders() && ! app(CustomerCommerce::class)->settings($store)->closing];
            },
            'storeSandbox' => fn () => $request->attributes->get('store') ? (bool) ($request->route('order') instanceof CustomerOrder ? $request->route('order')->is_sandbox : $request->attributes->get('store')->sandbox) : false,
            'workspace' => fn () => app(WorkspaceContext::class)->forRequest($request),
            'notificationSummary' => fn () => app(PanelNotifications::class)->summary($request),
            'flash' => ['error' => fn () => $request->session()->get('error'), 'success' => fn () => $request->session()->get('success')]];
    }
}
