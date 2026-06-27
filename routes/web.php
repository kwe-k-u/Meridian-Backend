<?php

// ── Web Routes ──
// Non-API frontend-facing routes (landing page, etc.).

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
