<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/forgot-password', [AuthController::class, 'sendResetLink']);
Route::post('/auth/reset-password', [AuthController::class, 'resetForgotPassword']);
Route::post('/auth/register-company', [AuthController::class, 'registerCompany']);
