<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePlatformHost
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->attributes->get('platform_host'), 404);

        return $next($request);
    }
}
