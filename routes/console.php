<?php

// ── Console Artisan Commands ──
// Define custom Artisan commands and scheduled tasks here.

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
