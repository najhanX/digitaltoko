<?php

use App\Http\Controllers\AdminLoginController;
use App\Http\Controllers\BotStateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OwnerAuthController;
use App\Http\Controllers\OwnerOnboardingController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\StaffLoginController;
use App\Http\Controllers\StoreManagementController;
use App\Http\Controllers\VerificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::middleware('platform.host')->group(function () {
    Route::get('/login', [OwnerAuthController::class, 'create'])->name('login');
    Route::post('/login/otp', [OwnerAuthController::class, 'requestOtp'])->middleware('throttle:6,1');
    Route::post('/login/verify', [OwnerAuthController::class, 'verify'])->middleware('throttle:10,1');
    Route::get('/admin/login', [AdminLoginController::class, 'create']);
    Route::post('/admin/login', [AdminLoginController::class, 'store'])->middleware('throttle:6,1');
    Route::middleware('auth:web')->group(function () {
        Route::get('/owner/profile', [OwnerOnboardingController::class, 'show']);
        Route::post('/owner/profile/email/otp', [OwnerOnboardingController::class, 'emailOtp'])->middleware('throttle:3,1');
        Route::post('/owner/profile/email/verify', [OwnerOnboardingController::class, 'verifyEmail'])->middleware('throttle:10,1');
        Route::post('/owner/profile/verification', [OwnerOnboardingController::class, 'submit'])->middleware('throttle:3,1');
        Route::delete('/owner/profile/identity', [OwnerOnboardingController::class, 'clean']);
        Route::get('/identity/{owner}/document', [VerificationController::class, 'document'])->whereNumber('owner');
        Route::get('/admin/verifications', [VerificationController::class, 'index']);
        Route::post('/admin/verifications/{owner}', [VerificationController::class, 'review'])->whereNumber('owner');
        Route::get('/owner/stores/create', [StoreManagementController::class, 'create']);
        Route::post('/owner/stores', [StoreManagementController::class, 'store']);
        Route::get('/owner/stores/{store}/manage', [StoreManagementController::class, 'show']);
        Route::patch('/owner/stores/{store}/manage', [StoreManagementController::class, 'update']);
        Route::post('/owner/stores/{store}/staff', [StoreManagementController::class, 'saveStaff']);
        Route::patch('/owner/stores/{store}/staff/{staff}', [StoreManagementController::class, 'saveStaff'])->whereNumber('staff');
        Route::delete('/owner/stores/{store}/staff/{staff}', [StoreManagementController::class, 'deleteStaff'])->whereNumber('staff');
    });
    Route::get('/preview/{surface}', PreviewController::class);
    Route::get('/admin', [PanelController::class, 'admin'])->middleware('auth:web');
    Route::get('/owner', [PanelController::class, 'owner'])->middleware('auth:web');
    Route::get('/owner/stores/{store}', [PanelController::class, 'store'])->middleware('auth:web');
    Route::get('/stores/{store}/bot-state', BotStateController::class)->middleware('throttle:60,1');
    Route::get('/staff/{store}/login', [StaffLoginController::class, 'create']);
    Route::post('/staff/{store}/login', [StaffLoginController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/staff/{store}/dashboard', [PanelController::class, 'staff'])->middleware('auth:staff');
    Route::post('/logout', [StaffLoginController::class, 'destroy']);
});
