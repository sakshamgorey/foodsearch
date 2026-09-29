<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // One global budget for every worker and every run: OFF limits per IP,
        // not per job. Backed by the cache store, so it must be shared
        // (database/redis) when you run more than one worker box.
        RateLimiter::for('openfoodfacts', fn () => Limit::perMinute(
            config('foodfacts.requests_per_minute')
        ));
    }
}
