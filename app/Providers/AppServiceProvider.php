<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Core service provider for the application.
 * Registers and bootstraps application-wide services.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Prevents Laravel from auto-wrapping API Resource responses in a top-level "data" key.
        // Note: no controller in this app actually uses Illuminate's JsonResource/ApiResource
        // classes — every endpoint returns raw Eloquent models/arrays via response()->json(),
        // which was never wrapped in the first place — so this line currently has no visible
        // effect. It would matter if resource classes are introduced later.
        JsonResource::withoutWrapping();
    }
}
