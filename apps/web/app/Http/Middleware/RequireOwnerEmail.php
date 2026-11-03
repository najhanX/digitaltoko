<?php

namespace App\Http\Middleware;

use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireOwnerEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $owner = $request->user('web');
        $panel = in_array('platform.host', $request->route()?->gatherMiddleware() ?? [], true)
            && $request->is('owner', 'owner/*', 'stores/*');
        if ($panel && $owner?->role === 'owner' && (app(WorkspaceContext::class)->forRequest($request)['role'] ?? null) === 'owner' && ! config('backup.preview')
            && $owner->requiresEmailVerification()
            && ! $request->isMethodSafe() && ! $request->is('owner/profile/email/otp', 'owner/profile/email/verify')) {
            return redirect('/owner/account', 303)->with('error', 'Verifikasi email akun terlebih dahulu.');
        }

        return $next($request);
    }
}
