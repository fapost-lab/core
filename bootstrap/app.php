<?php

declare(strict_types=1);

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Staff\Http\Middleware\EndExpiredSupportSession;
use App\Domains\Staff\Http\Middleware\EnsureUserIsActive;
use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantSlugMovedException;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\Support\TenantHost;
use App\Http\Middleware\EnsureCanAccessPanel;
use App\Http\Middleware\ForgetInvalidAuthenticatedSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectTenantRootToAdmin;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use App\Http\Middleware\ResolveCurrentAssistant;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SetConsoleRootView;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TenancyMiddleware;
use App\Http\Middleware\TmaAuthMiddleware;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
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

            // Registered after the Filament panels on purpose: a route with the same method, domain, URI and name
            // replaces Filament's (see routes/inertia.php). Off by default; takes effect after a restart.
            if (config('ui.inertia')) {
                Route::group([], base_path('routes/inertia.php'));
            }
        }
    )
    // Live updates: `/broadcasting/auth` answers in the tenant of the host, for a signed-in user who passes the console's
    // account checks; each channel then checks its own subject (routes/channels.php).
    ->withBroadcasting(__DIR__ . '/../routes/channels.php', ['middleware' => ['broadcasting']])
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

        // A session that holds a user id the provider cannot use (a numeric id from before ULID keys) has to be
        // forgotten before anything asks the guard for the user: on PostgreSQL a non-uuid id is a query error.
        // Unlisted middleware sorts after the listed ones, which put `auth` ahead of it in the console stacks.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ForgetInvalidAuthenticatedSession::class);

        // Stacks of the Inertia console (routes/inertia.php), the Filament panels' checks in the same order:
        // the tenant first (sessions live in the tenant schema), then the session, authentication and
        // the account checks that Filament repeats on every request. `console` adds the assistant of the
        // URL, whose parameter keeps Filament's name `{tenant}` until Filament goes.
        $admin = [
            'web',
            'tenant',
            ForgetInvalidAuthenticatedSession::class,
            'auth',
            AuthenticateSession::class,
            EnsureUserIsActive::class,
            EnsureCanAccessPanel::class,
            RefuseWritesWhenTenantStopped::class,
            SetConsoleRootView::class,
        ];

        $middleware->group('admin', $admin);
        // The authorization of private broadcast channels: the `admin` stack without the console's view and the write
        // refusal of a stopped tenant (asking to listen changes nothing).
        $middleware->group('broadcasting', [
            'web',
            'tenant',
            ForgetInvalidAuthenticatedSession::class,
            'auth',
            AuthenticateSession::class,
            EnsureUserIsActive::class,
            EnsureCanAccessPanel::class,
        ]);
        $middleware->group('console', [...$admin, ResolveCurrentAssistant::class . ':tenant']);

        $middleware->web(
            append: [
                SetLocale::class,
                HandleInertiaRequests::class,
                EndExpiredSupportSession::class,
            ],
            prepend: [
                ResolveTenantContext::class,
            ],
        );

        // The support entry is a form post from the operator's own site, so no CSRF token can accompany it:
        // the single-use token in its body is the proof (see SupportAccessController).
        $middleware->validateCsrfTokens(except: ['support/enter']);

        // Which addresses may vouch for X-Forwarded-* is `trustedproxy.proxies` (TRUSTED_PROXIES), read per request.
        // The client address and scheme are believed from such a proxy; the host, the port and the path prefix are
        // not. The host selects the tenant (and TrustHosts runs on it before this middleware) and every proxy here
        // preserves it anyway; no proxy of ours sets a port, so a client's X-Forwarded-Port would pass straight
        // through and change the absolute URLs built in a request. The port follows the scheme.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        // Registered in every mode; the closure runs per request, after config is loaded, and
        // yields no patterns in `single` mode, which leaves every host trusted as before.
        $middleware->trustHosts(at: fn (): array => TenantHost::trustedHostPatterns(), subdomains: false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A slug a tenant gave up redirects to its new host for a while, but only for requests that can safely be
        // repeated there. 302 on purpose: a browser keeps no 302, so a tenant that takes its slug back or a redirect
        // that expires is not stuck behind a cached one. Any other method gets the 404 a missing tenant gets. Not an
        // error, so not reported.
        $exceptions->dontReport(TenantSlugMovedException::class);

        $exceptions->render(function (TenantSlugMovedException $exception, Request $request) {
            if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
                return app(ExceptionHandler::class)->render($request, new NotFoundHttpException('', $exception));
            }

            return redirect()->away(TenantHost::urlFor($exception->tenant, $request->getRequestUri()), 302);
        });

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
