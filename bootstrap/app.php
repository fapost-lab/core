<?php

declare(strict_types=1);

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectTenantRootToAdmin;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TenancyMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
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

            Route::middleware('web')->group(base_path('routes/builder.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        $middleware->alias([
            'tenant'               => TenancyMiddleware::class,
            'tenant.root.redirect' => RedirectTenantRootToAdmin::class,
        ]);

        $middleware->web(
            append: [
                SetLocale::class,
                HandleInertiaRequests::class,
            ],
            prepend: [
                TenancyMiddleware::class,
            ],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (DraftVersionConflictException $exception, Request $request) {
            if ( ! $request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
                return null;
            }

            return response()->json([
                'error'   => 'draft_conflict',
                'message' => 'Draft was modified in another session. Reload and retry.',
            ], 409);
        });

        $exceptions->render(function (FlowValidationException $exception, Request $request) {
            if ( ! $request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
                return null;
            }

            return response()->json([
                'error'  => 'validation_failed',
                'errors' => $exception->errors,
            ], 422);
        });
    })->create();
