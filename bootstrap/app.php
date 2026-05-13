<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
        ->withRouting(
            web: __DIR__.'/../routes/web.php',
            api: __DIR__.'/../routes/api.php',
            commands: __DIR__.'/../routes/console.php',
            health: '/up',
            then: function () {
                // Admin routes — no domain constraint needed
                Route::middleware('web')
                    ->group(base_path('routes/admin.php'));

                // Reseller subdomain routes only
                Route::middleware('web')
                    ->domain('{subdomain}.' . env('APP_BASE_DOMAIN', 'virextra.com'))
                    ->group(base_path('routes/reseller.php'));

                // ❌ REMOVE the custom domain block entirely — it was
                // loading reseller.php unconditionally and killing web.php
            },
        )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\ResolveResellerSubdomain::class,
        ]);

        $middleware->alias([
            'admin'             => \App\Http\Middleware\AdminMiddleware::class,
            'api.key'           => \App\Http\Middleware\ApiKeyMiddleware::class,
            'reseller.panel'    => \App\Http\Middleware\EnsureResellerPanel::class,
            'reseller.customer' => \App\Http\Middleware\EnsureResellerCustomer::class,
        ]);

        $middleware->group('api', [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
        ->withExceptions(function (Exceptions $exceptions): void {
            $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }

                // If on a reseller subdomain, stay on that domain
                if (app()->bound('current_reseller')) {
                    return redirect('/login');
                }

                return redirect()->guest(route('login'));
            });
        })->create();