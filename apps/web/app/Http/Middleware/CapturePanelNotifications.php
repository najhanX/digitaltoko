<?php

namespace App\Http\Middleware;

use App\Services\PanelNotifications;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class CapturePanelNotifications
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $notification = app(PanelNotifications::class)->captureAction($request, $response);
        if ($notification) {
            Inertia::flash('panelNotice', $notification);
        }

        return $response;
    }
}
