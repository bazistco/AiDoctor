<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('dashboard-limiter',function (Request $request){
            if(auth()->check()){
                return Limit::perMinute(60)->by($request->user()->id);
            }
            return Limit::perMinute(40)->by($request->ip());
        });
    }
}
