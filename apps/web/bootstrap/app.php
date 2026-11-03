<?php

use App\Http\Controllers\RecoveryStatusController;
use App\Http\Middleware\AuthenticatePanelSession;
use App\Http\Middleware\CapturePanelNotifications;
use App\Http\Middleware\ConfiguredTrustProxies;
use App\Http\Middleware\GuardRecoveryRuntime;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ObserveCommerceWebhook;
use App\Http\Middleware\ProtectPurgingStore;
use App\Http\Middleware\RequireOwnerEmail;
use App\Http\Middleware\RequirePlatformHost;
use App\Http\Middleware\ResolvePlatformHost;
use App\Models\Store;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::get('/recovery-review', [RecoveryStatusController::class, 'review'])->name('recovery.review');
            Route::get('/recovery-status/{id}', [RecoveryStatusController::class, 'show'])->middleware('signed')->name('recovery.status');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([GuardRecoveryRuntime::class, ObserveCommerceWebhook::class]);
        $middleware->preventRequestsDuringMaintenance(except: ['recovery-status/*', 'recovery-review']);
        $middleware->web(prepend: [ResolvePlatformHost::class], append: [AuthenticatePanelSession::class, RequireOwnerEmail::class, ProtectPurgingStore::class, HandleInertiaRequests::class, CapturePanelNotifications::class]);
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
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'pin', 'callback_token', 'api_key', 'webhook_secret', 'server_key', 'qris_payload', 'forward_token', 'otp', 'credentials', 'payload', 'entries', 'url', 'catalog_config']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
