<?php

use App\Http\Middleware\CheckUserActive;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Illuminate\Http\Request; // Import the fully qualified Request class

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
        then: function (){
            Route::prefix('ai')->group(function () {
                Route::middleware('api')
//            ->domain('api.'.env('DOMAIN_URL'))
                    ->group(base_path('routes/ai.php'));
            });
            Route::prefix('api')->group(function () {
                Route::middleware('api')
//            ->domain('api.'.env('DOMAIN_URL'))
                    ->group(base_path('routes/api.php'));
            });
            Route::middleware('api')
                ->prefix('api/owner')
                ->group(base_path('routes/owner.php'));

        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'api.rate.limit' => \App\Http\Middleware\CheckApiRateLimit::class,
            'api.check.admin' => \App\Http\Middleware\CheckApiAdmin::class,
            'role'      => \App\Http\Middleware\CheckRole::class,
            'ownership' => \App\Http\Middleware\CheckOwnership::class,
            'user.active' => CheckUserActive::class,
        ]);
        $middleware->redirectGuestsTo(function ($request) { // No type hint here, or use fully qualified
            if ($request->expectsJson() || $request->is('api/*') || $request->is('ai/*')) {
                return null;
            }
            return route('patient.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });
        $exceptions->render(function (AuthenticationException $e, $request) {
                return response()->json([
                    'message' => 'Unauthenticated.'
                ], 401);
        });
        $exceptions->renderable(function (AuthenticationException $e, Request $request) { // Use Illuminate\Http\Request
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'error' => 'Authentication required.'
                ], 401);
            }
        });
        $exceptions->renderable(function (AuthorizationException $e, Request $request) { // Use Illuminate\Http\Request
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unauthorized.',
                    'error' => 'Forbidden.'
                ], 403);
            }
        });
        $exceptions->renderable(function (\Throwable $e, Request $request) { // Use Illuminate\Http\Request
            if ($e instanceof ValidationException) {
                $errors = $e->errors();
                // Assuming error_response is a helper function
                return error_response('InvalidParams', 400, $errors);
            }
            if ($e instanceof AuthorizationException or $e instanceof AccessDeniedHttpException) {
                return error_response('Forbidden', 403, [
                    'permission' => 'You do not have permission to perform this action'
                ]);
            }
            if ( $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'Server Error',
                    'error' => 'Something went wrong'
                ], 500);
            }
            // Assuming error_response is a helper function
            return error_response('Something went wrong in api', 500, [
                'message' => $e->getMessage() ?: 'Server Error',
                'error' => 'Something went wrong in api'
            ]);
        });

    })->create();
