<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        using: function (){
            Route::middleware('web')
//                ->domain('patient.'.env('DOMAIN_URL'))
                ->as('patient.')
                ->group(base_path('routes/web/patient.php'));
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(function ($request) {
                return route('patient.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->reportable(function (\Exception $e) {
            Log::error("haha  : " . $e->getMessage());
        });

    })->create();
