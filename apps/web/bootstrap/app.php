<?php

use App\Http\Middleware\CapturePanelNotifications;
use App\Http\Middleware\ConfiguredTrustProxies;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ProtectPurgingStore;
use App\Http\Middleware\RequirePlatformHost;
use App\Http\Middleware\ResolvePlatformHost;
use App\Models\Store;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(prepend: [ResolvePlatformHost::class], append: [ProtectPurgingStore::class, HandleInertiaRequests::class, CapturePanelNotifications::class]);
        $middleware->api(prepend: [ResolvePlatformHost::class]);
        $middleware->trimStrings(except: ['pin', 'payload', 'entries.*', 'content.*.text', 'config.rules.*.captures.*.prefix', 'config.rules.*.captures.*.suffix', 'config.rules.*.keyword', 'config.rules.*.regex', 'config.rules.*.template', 'text']);
        $middleware->alias(['platform.host' => RequirePlatformHost::class]);
        $middleware->replace(TrustProxies::class, ConfiguredTrustProxies::class);
        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->is('staff/*')) {
                $store = $request->route('store');
                $id = $store instanceof Store ? $store->id : (string) $store;

                return '/staff/'.rawurlencode($id).'/login';
            }

            return $request->is('admin*') ? '/admin/login' : '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'pin', 'callback_token', 'api_key', 'webhook_secret', 'server_key', 'qris_payload', 'forward_token', 'otp', 'credentials', 'payload', 'entries']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
