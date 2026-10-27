<?php

use App\Http\Controllers\AdminScheduleController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeContentController;   // ← bago
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SocialAuthController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/google', [AuthController::class, 'handleGoogleAuth']);

Route::post('/auth/facebook', [SocialAuthController::class, 'facebook']);
Route::post('/email/verify', [SocialAuthController::class, 'verify'])->middleware('throttle:6,1');
Route::post('/email/resend', [SocialAuthController::class, 'resend'])->middleware('throttle:3,1');

// Public: kailangan din ng Client Site home page            // ← bago
Route::get('/home-content', [HomeContentController::class, 'show']);   // ← bago

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/avatar', [ProfileController::class, 'avatar']);
    Route::post('/profile/avatar/remove', [ProfileController::class, 'removeAvatar']);
    Route::post('/profile/password', [ProfileController::class, 'password']);

    // Email change (6-digit code verification)
    Route::post('/profile/email/request', [ProfileController::class, 'requestEmailChange'])->middleware('throttle:5,1');
    Route::post('/profile/email/confirm', [ProfileController::class, 'confirmEmailChange'])->middleware('throttle:10,1');

    // Admin / affiliate schedules
    Route::middleware('role:ADMIN,AFFILIATE')->prefix('admin/schedule')->group(function () {
        Route::get('/', [AdminScheduleController::class, 'index']);
        Route::post('/slots', [AdminScheduleController::class, 'store']);
        Route::delete('/slots/{id}', [AdminScheduleController::class, 'destroy']);
    });

    // Admin only: manage home page content                    // ← bago
    Route::middleware('role:ADMIN')->group(function () {       // ← bago
        Route::put('/home-content', [HomeContentController::class, 'update']);
        Route::post('/home-content/image', [HomeContentController::class, 'uploadImage']);
    });
});
