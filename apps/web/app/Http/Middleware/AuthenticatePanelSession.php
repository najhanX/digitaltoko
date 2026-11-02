<?php

namespace App\Http\Middleware;

use App\Models\Store;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePanelSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = $request->route()?->gatherMiddleware() ?? [];
        $protected = in_array('platform.host', $panel, true) && $request->is('stores/*', 'owner', 'owner/*', 'admin', 'admin/*', 'staff/*');
        $login = $request->is('login', 'admin/login', 'staff/*/login');
        if ($protected && ! $login && ! $request->user('web') && ! $request->user('staff')) {
            $url = '/login';
            if ($request->is('admin', 'admin/*')) {
                $url = '/admin/login';
            } elseif ($request->is('staff/*') || $request->cookie('panel_login_role') === 'staff') {
                $store = $request->route('store');
                $id = $store instanceof Store ? $store->id : (string) $store;
                if ($id !== '') {
                    $url = '/staff/'.rawurlencode($id).'/login';
                }
            }
            throw new AuthenticationException('Sesi berakhir. Silakan masuk kembali.', ['web', 'staff'], $url);
        }
        $response = $next($request);
        if ($protected && ($request->user('web') || $request->user('staff'))) {
            $response->headers->setCookie(cookie('panel_login_role', $request->user('web') ? 'owner' : 'staff', 43200, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
