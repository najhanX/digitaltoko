<?php

use App\Http\Controllers\BotStateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\StaffLoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', HomeController::class)->name('home');
Route::middleware('platform.host')->group(function () {
    Route::get('/login', fn () => Inertia::render('Auth/Login', ['mode' => 'owner', 'store' => null, 'ownerOtpReady' => false]))->name('login');
    Route::get('/preview/{surface}', PreviewController::class);
    Route::get('/admin', [PanelController::class, 'admin'])->middleware('auth');
    Route::get('/owner', [PanelController::class, 'owner'])->middleware('auth');
    Route::get('/owner/stores/{store}', [PanelController::class, 'store'])->middleware('auth');
    Route::get('/stores/{store}/bot-state', BotStateController::class)->middleware('throttle:60,1');
    Route::get('/staff/{store}/login', [StaffLoginController::class, 'create']);
    Route::post('/staff/{store}/login', [StaffLoginController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/staff/{store}/dashboard', [PanelController::class, 'staff'])->middleware('auth:staff');
    Route::post('/logout', [StaffLoginController::class, 'destroy']);
});
