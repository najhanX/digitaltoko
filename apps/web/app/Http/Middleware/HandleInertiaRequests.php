<?php

namespace App\Http\Middleware;

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
            'workspace' => fn () => app(WorkspaceContext::class)->forRequest($request),
            'notificationSummary' => fn () => app(PanelNotifications::class)->summary($request),
            'flash' => ['error' => fn () => $request->session()->get('error'), 'success' => fn () => $request->session()->get('success')]];
    }
}
