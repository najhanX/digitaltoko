<?php

namespace App\Http\Middleware;

use App\Models\CustomerOrder;
use App\Services\CustomerAccess;
use App\Services\CustomerCommerce;
use App\Services\PanelNotifications;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'brand' => ['name' => 'DigitalToko'],
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
