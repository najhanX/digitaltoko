<?php

use App\Http\Controllers\BotAccessController;
use App\Http\Controllers\BotEventController;
use Illuminate\Support\Facades\Route;

Route::post('/internal/bot/events', BotEventController::class)->middleware(['platform.host', 'throttle:bot-worker']);
Route::get('/internal/bot/access', BotAccessController::class)->middleware(['platform.host', 'throttle:bot-worker']);
