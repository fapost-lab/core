<?php

declare(strict_types=1);

use App\Domains\Flow\Exceptions\DraftVersionConflictException;
use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Staff\Http\Middleware\EndExpiredSupportSession;
use App\Domains\Staff\Http\Middleware\EnsureUserIsActive;
use App\Domains\Staff\Support\ApiTokenGuard;
use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantSlugMovedException;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\Support\TenantHost;
use App\Http\Middleware\EnsureCanAccessPanel;
use App\Http\Middleware\EnsureSolutionActive;
use App\Http\Middleware\EnsureSolutionPage;
use App\Http\Middleware\EnsureTenantOnboarded;
use App\Http\Middleware\ForgetInvalidAuthenticatedSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectTenantRootToAdmin;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use App\Http\Middleware\ResolveCurrentAssistant;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SetConsoleRootView;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TakeOAuthCallbackQuery;
use App\Http\Middleware\TenancyMiddleware;
use App\Http\Middleware\TmaAuthMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
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
            // No `web` group: an anonymous fetch by a messenger must start no session and set no cookie.
            Route::group([], base_path('routes/media-public.php'));
            // No `web` group: a flow's webhook trigger is called by outside systems, with no session and no CSRF.
            Route::group([], base_path('routes/flow-webhook.php'));
            Route::middleware('web')->group(base_path('routes/tma.php'));
            // No `web` group: the API of a tenant is called by outside systems with a tenant API token, with no
            // session, CSRF or cookie. The stack is the `api-token` group below.
            Route::middleware(ApiTokenGuard::MIDDLEWARE_GROUP)->prefix('api/v1')->group(base_path('routes/api.php'));

            // The console: the admin and assistant screens, sign-in and the routes of Solutions.
            Route::group([], base_path('routes/inertia.php'));
        }
    )
    // Live updates: `/broadcasting/auth` answers in the tenant of the host, for a signed-in user who passes the console's
    // account checks; each channel then checks its own subject (routes/channels.php).
    ->withBroadcasting(__DIR__ . '/../routes/channels.php', ['middleware' => ['broadcasting']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('console.auth.login'));

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

        // The OAuth2 consent callback's query (the code, the state) comes off the request before `auth` can keep the
        // full URL of a guest as the intended one, or a refusal leaves it as the session's previous URL.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: TakeOAuthCallbackQuery::class);

        // Stacks of the console (routes/inertia.php): the tenant first (sessions live in the tenant schema), then the
        // session, authentication and the account checks repeated on every request, then the hold of a tenant that is
        // still in the setup wizard (the builder and media routes carry it themselves). `console` adds the assistant of
        // the URL, the route parameter `{assistant}`.
        $admin = [
            'web',
            'tenant',
            ForgetInvalidAuthenticatedSession::class,
            'auth',
            AuthenticateSession::class,
            EnsureUserIsActive::class,
            EnsureCanAccessPanel::class,
            EnsureTenantOnboarded::class,
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
        $middleware->group('console', [...$admin, ResolveCurrentAssistant::class . ':assistant,operate']);

        // The tenant API (adr-20261119-tenant-api-tokens §5): the tenant of the host (`tenant` resolves it, as
        // ResolveTenantContext does for `web`, and answers 404 on the base domain, a platform subdomain or a foreign
        // host), then its token, then the token's rate limit, then the write refusal of a stopped tenant. No session,
        // CSRF or cookies.
        $middleware->group(ApiTokenGuard::MIDDLEWARE_GROUP, [
            'tenant',
            'auth:' . ApiTokenGuard::NAME,
            'throttle:' . ApiTokenGuard::LIMITER,
            RefuseWritesWhenTenantStopped::class,
        ]);

        // Routes of Solutions (App\Http\Shell\SolutionRoutes): the console's stacks, then 404 unless the Solution is
        // served to the tenant, and a check that the route renders only the Solution's own pages.
        $middleware->group('solution', [...$admin, EnsureSolutionActive::class, EnsureSolutionPage::class]);
        $middleware->group('solution.assistant', [...$admin, ResolveCurrentAssistant::class, EnsureSolutionActive::class, EnsureSolutionPage::class]);

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
        $middleware->preventRequestForgery(except: ['support/enter']);

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
        // A refused form is sent back with its input flashed to the session; the secrets of a credential form are not
        // flashed (the form shows them empty again), and Laravel's own defaults (passwords) are kept.
        $exceptions->dontFlash(['secrets']);

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

        // A failed tenant API token is the same bare 401 whatever the reason and whatever the client accepts: never the
        // console's redirect to its sign-in page.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! in_array(ApiTokenGuard::NAME, $exception->guards(), true)) {
                return null;
            }

            return response()->json(['error' => 'unauthenticated'], 401);
        });

        // The tenant API answers every refusal the framework raises — the token's rate limit (429), an address that names
        // no route (404), another method (405), a body over PHP's limit (413) — as JSON `{"error":"<code>"}`, like its own
        // refusals, whatever the client accepts: an outside system gets no HTML page. The headers stay (`Retry-After`).
        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            $status = $exception->getStatusCode();
            $code   = match ($status) {
                404     => 'not_found',
                405     => 'method_not_allowed',
                413     => 'payload_too_large',
                429     => 'too_many_requests',
                503     => 'service_unavailable',
                default => 'http_' . $status,
            };

            return response()->json(['error' => $code], $status, $exception->getHeaders());
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
