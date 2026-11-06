<?php

use App\Http\Controllers\BotAccessController;
use App\Http\Controllers\BotCatalogController;
use App\Http\Controllers\BotCommerceController;
use App\Http\Controllers\BotEventController;
use App\Http\Controllers\BotQrisMessageController;
use App\Http\Controllers\CommerceWebhookController;
use App\Http\Controllers\DigiflazzWebhookController;
use App\Http\Controllers\OtomaxCallbackController;
use App\Http\Controllers\StoreDomainController;
use App\Http\Middleware\ResolvePlatformHost;
use Illuminate\Support\Facades\Route;

Route::get('/domains/proof', [StoreDomainController::class, 'proof'])->withoutMiddleware(ResolvePlatformHost::class)->middleware('throttle:120,1,domain-proof');
Route::get('/domains/tls-ask/{key}', [StoreDomainController::class, 'tlsAsk'])->withoutMiddleware(ResolvePlatformHost::class)->middleware('throttle:120,1,domain-tls-ask');

Route::post('/internal/bot/events', BotEventController::class)->middleware(['platform.host', 'throttle:bot-worker']);
Route::get('/internal/bot/access', BotAccessController::class)->middleware(['platform.host', 'throttle:bot-worker']);
Route::get('/internal/bot/products', BotCatalogController::class)->middleware(['platform.host', 'throttle:bot-worker']);

Route::get('/internal/bot/catalog', [BotCatalogController::class, 'browse'])->middleware(['platform.host', 'throttle:bot-worker']);

Route::post('/commerce/midtrans/{store}', [CommerceWebhookController::class, 'midtrans'])->middleware(['platform.host', 'throttle:120,1,commerce-commerce-midtrans-store']);
Route::post('/commerce/digiflazz', DigiflazzWebhookController::class)->middleware(['platform.host', 'throttle:600,1,digiflazz-callback'])->name('commerce.digiflazz');
Route::post('/commerce/forward/{store}', [CommerceWebhookController::class, 'forward'])->middleware(['platform.host', 'throttle:120,1,commerce-commerce-forward-store']);
Route::post('/commerce/qris-profiles/{profile}/forward', [CommerceWebhookController::class, 'forwardProfile'])->middleware(['platform.host', 'throttle:120,1,commerce-qris-profile']);
Route::post('/internal/bot/commerce', [BotCommerceController::class, 'handle'])->middleware(['platform.host', 'throttle:bot-worker']);
Route::get('/internal/bot/notices', [BotCommerceController::class, 'notices'])->middleware(['platform.host', 'throttle:bot-notices']);
Route::post('/internal/bot/notices/{notice}/delivered', [BotCommerceController::class, 'delivered'])->middleware(['platform.host', 'throttle:bot-notices']);
Route::post('/internal/bot/qris-messages', [BotQrisMessageController::class, 'store'])->middleware(['platform.host', 'throttle:bot-notices']);
Route::post('/internal/bot/qris-messages/{message}/revoked', [BotQrisMessageController::class, 'revoked'])->middleware(['platform.host', 'throttle:bot-notices']);

Route::match(['get', 'post'], '/commerce/otomax/{connection}/{token}', OtomaxCallbackController::class)->middleware(['platform.host', 'throttle:300,1,otomax-callback']);
