<?php

declare(strict_types=1);

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\Support\TenantHost;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectTenantRootToAdmin;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TenancyMiddleware;
use App\Http\Middleware\TmaAuthMiddleware;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            Route::middleware('web')->group(base_path('routes/media.php'));
            Route::middleware('web')->group(base_path('routes/tma.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));

        $middleware->alias([
            'tenant'               => TenancyMiddleware::class,
            'tenant.root.redirect' => RedirectTenantRootToAdmin::class,
            'tma.auth'             => TmaAuthMiddleware::class,
        ]);

        // `tenant` must run ahead of the whole cookie/session stack, not just `auth`: sessions and
        // users live in the tenant schema, and a request that names no tenant has to be answered
        // 404 before anything asks it to log in. EncryptCookies is the first entry of that stack.
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: TenancyMiddleware::class);

        $middleware->web(
            append: [
                SetLocale::class,
                HandleInertiaRequests::class,
            ],
            prepend: [
                ResolveTenantContext::class,
            ],
        );

        // Registered in every mode; the closure runs per request, after config is loaded, and
        // yields no patterns in `single` mode, which leaves every host trusted as before.
        $middleware->trustHosts(at: fn (): array => TenantHost::trustedHostPatterns(), subdomains: false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // In host mode a host that names no servable tenant is "not found" to the client. Only the
        // response changes: the exception is still reported, so a queued job that loses its tenant
        // stays visible. In single mode the host names nothing, so a missing tenant is a
        // misconfiguration and stays a server error.
        $exceptions->render(function (TenantNotFoundException|TenantNotActiveException $exception, Request $request) {
            if (TenancyResolutionMode::Host !== TenancyResolutionMode::tryFrom((string) config('tenancy.resolution'))) {
                return null;
            }

            return app(ExceptionHandler::class)->render($request, new NotFoundHttpException('', $exception));
        });

        $exceptions->render(function (DraftVersionConflictException $exception, Request $request) {
            if (! $request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
                return null;
            }

            return response()->json([
                'error'   => 'draft_conflict',
                'message' => 'Draft was modified in another session. Reload and retry.',
            ], 409);
        });

        $exceptions->render(function (FlowValidationException $exception, Request $request) {
            if (! $request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
                return null;
            }

            return response()->json([
                'error'  => 'validation_failed',
                'errors' => $exception->errors,
            ], 422);
        });
    })->create();
