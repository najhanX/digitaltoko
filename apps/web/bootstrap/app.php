<?php

use App\Http\Middleware\ConfiguredTrustProxies;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePlatformHost;
use App\Http\Middleware\ResolvePlatformHost;
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
        $middleware->web(prepend: [ResolvePlatformHost::class], append: [HandleInertiaRequests::class]);
        $middleware->api(prepend: [ResolvePlatformHost::class]);
        $middleware->alias(['platform.host' => RequirePlatformHost::class]);
        $middleware->replace(TrustProxies::class, ConfiguredTrustProxies::class);
        $middleware->redirectGuestsTo(fn (Request $request) => '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
