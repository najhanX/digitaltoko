<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\StoreMaintenance;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class GuardStoreMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->attributes->get('store');
        if (! $store instanceof Store || $request->is('maintenance', 'orders/*', 'cek-pesanan', 'cek-pesanan/detail', 'storefront-media/*', 'article-media/*', 'storage/*')) {
            return $next($request);
        }
        if (in_array($request->method(), ['GET', 'HEAD'], true) && ! $request->is('account/register')) {
            return $next($request);
        }
        $account = $request->is('account', 'account/*') && ! $request->is('account/register')
            && ! ($request->is('account/otp') && $request->input('intent') === 'register');
        if ($account || ! ($active = app(StoreMaintenance::class)->active($store))) {
            return $next($request);
        }
        if ($request->header('X-Inertia')) {
            return Inertia::location('/maintenance')->header('Cache-Control', 'no-store');
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Layanan sedang maintenance. '.$active['message'], 'maintenance' => $active], 503)->header('Cache-Control', 'no-store');
        }

        return redirect('/maintenance', 303)->header('Cache-Control', 'no-store');
    }
}
