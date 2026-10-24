<?php

use App\Http\Controllers\AdminScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/schedule')->group(function () {
    Route::get('/', [AdminScheduleController::class, 'index']);
    Route::post('/slots', [AdminScheduleController::class, 'store']);
    Route::delete('/slots/{id}', [AdminScheduleController::class, 'destroy']);
});