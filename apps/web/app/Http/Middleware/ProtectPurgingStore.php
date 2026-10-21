<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Services\StoreRetention;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ProtectPurgingStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->route('store');
        $store = $store instanceof Store ? $store : $request->attributes->get('store');
        if ($store instanceof Store && ! $request->isMethodSafe() && ! $request->attributes->has('store') && ! $request->routeIs('admin.stores.destroy') && ($store->status === 'purging' || $store->purge_started_at)) {
            $field = $request->is('stores/*/bot/*') ? 'connection' : ($request->is('admin/stores/*') ? 'action' : 'retention');
            throw ValidationException::withMessages([$field => 'Toko sedang dihapus dan tidak dapat diubah. Hubungi administrator.']);
        }
        if ($store instanceof Store && $request->attributes->has('store')) {
            StoreRetention::guard($store);
        }

        return $next($request);
    }
}
