<?php

namespace App\Http\Middleware;

use App\Support\PlatformHost;
use Closure;
use Illuminate\Http\Request;

class ResolvePlatformHost
{
    public function handle(Request $request, Closure $next)
    {
        $hosts = app(PlatformHost::class);
        if ($hosts->isPlatform($request->getHost())) {
            $request->attributes->set('platform_host', true);
        } else {
            $store = $hosts->resolveStore($request->getHost());
            abort_unless($store, 404);
            $request->attributes->set('store', $store);
        }

        return $next($request);
    }
}
