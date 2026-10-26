<?php

use App\Http\Controllers\AdminScheduleController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialAuthController;

Route::post('/auth/google', [AuthController::class, 'handleGoogleAuth']);
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::post('/auth/google', [SocialAuthController::class, 'google']);
Route::post('/auth/facebook', [SocialAuthController::class, 'facebook']);
Route::post('/email/verify', [SocialAuthController::class, 'verify'])->middleware('throttle:6,1');
Route::post('/email/resend', [SocialAuthController::class, 'resend'])->middleware('throttle:3,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

   
    Route::middleware('role:ADMIN,AFFILIATE')->prefix('admin/schedule')->group(function () {
        Route::get('/', [AdminScheduleController::class, 'index']);
        Route::post('/slots', [AdminScheduleController::class, 'store']);
        Route::delete('/slots/{id}', [AdminScheduleController::class, 'destroy']);
    });
});