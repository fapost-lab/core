<?php

declare(strict_types=1);

use App\Http\Middleware\RedirectTenantRootToAdmin;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TenancyMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], base_path('app/Domains/Webhook/routes/webhook.php'));

            Route::middleware(['tenant', 'tenant.root.redirect'])
                ->group(base_path('routes/tenant.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant'               => TenancyMiddleware::class,
            'tenant.root.redirect' => RedirectTenantRootToAdmin::class,
        ]);

        $middleware->web(
            append: [
                SetLocale::class,
            ],
            prepend: [
                TenancyMiddleware::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
    })->create();
