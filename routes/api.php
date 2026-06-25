<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function() {
    Route::prefix('auth')->group(function() {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/register-company', [AuthController::class, 'registerCompany']);
        Route::post('/forgot-password', [AuthController::class, 'sendResetLink']);
        Route::post('/reset-password', [AuthController::class, 'resetForgotPassword']);
    });
});
