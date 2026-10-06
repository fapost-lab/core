<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

use App\Domains\Tenancy\Contracts\TenantInterface;

/**
 * Where a tenant's own surfaces are served.
 *
 * The base domain belongs to the platform: it carries the installation's welcome
 * page today and is reserved for a control plane that manages tenants. Anything
 * scoped to a single tenant — the admin panel, the assistant console — is served
 * from that tenant's own host instead, so the two can never be confused for one
 * another and the base domain stays free.
 */
final class TenantHost
{
    /** Where a tenant's administrators sign in, on the tenant's own host. */
    public const string ADMIN_LOGIN_PATH = '/admin/login';

    /**
     * Host for the tenant this installation resolves by default.
     *
     * Returns null when no tenant slug is configured, which leaves the panel
     * unconstrained rather than unreachable — a fresh checkout with nothing set
     * up should still be able to reach the panel and finish installing.
     */
    public static function forDefaultTenant(): ?string
    {
        $slug = config('tenancy.default_tenant_slug');
        $base = config('tenancy.base_domain');

        if (! is_string($slug) || '' === $slug) {
            return null;
        }

        if (! is_string($base) || '' === $base) {
            return null;
        }

        return $slug . '.' . $base;
    }

    /**
     * Absolute URL of a path on a tenant's own host, for places that have no request to borrow a host from.
     *
     * Mail and queued jobs build URLs outside a request, where `url()` falls back to `APP_URL` and so
     * to the base domain. In `host` mode the tenant is named by its host, so the URL is spelled out:
     * the scheme and port of `APP_URL`, then `<slug>.<base_domain>`. In `single` mode the host selects nothing
     * and `url()` is what it always was.
     *
     * @param  string  $path  Path with query; a missing leading slash is added.
     */
    public static function urlFor(TenantInterface $tenant, string $path): string
    {
        if (TenancyResolutionMode::Host !== TenancyResolutionMode::tryFrom((string) config('tenancy.resolution'))) {
            return url($path);
        }

        $appUrl = (string) config('app.url');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $scheme = is_string($scheme) && '' !== $scheme ? $scheme : 'https';
        $port   = parse_url($appUrl, PHP_URL_PORT);
        $port   = is_int($port) ? ':' . $port : '';

        return $scheme . '://' . $tenant->getSlug() . '.' . config('tenancy.base_domain') . $port . '/' . mb_ltrim($path, '/');
    }

    /**
     * Host the tenant panels are bound to, or null to leave them unbound.
     *
     * Evaluated once when the panels are registered, and frozen by `route:cache`,
     * so it can depend on the mode but never on the request. In `single` mode the
     * panels live on the one tenant's host. In `host` mode every tenant host serves
     * them, so the panels carry no domain and TenancyMiddleware, first in each
     * panel stack, decides who may reach them: a tenant host enters its tenant, the
     * base domain and foreign hosts get 404. Without a domain the generated URLs
     * follow the host of the current request.
     */
    public static function panelDomain(): ?string
    {
        if (TenancyResolutionMode::Host === TenancyResolutionMode::tryFrom((string) config('tenancy.resolution'))) {
            return null;
        }

        return self::forDefaultTenant();
    }

    /**
     * Host patterns the application answers to, as regular expressions for TrustHosts.
     *
     * In `host` mode the Host header selects the tenant, so only the base domain and
     * its subdomains may reach the application. In `single` mode the host selects
     * nothing and nothing is restricted, as before.
     *
     * @return list<string>
     */
    public static function trustedHostPatterns(): array
    {
        $base = config('tenancy.base_domain');

        if (TenancyResolutionMode::Host !== TenancyResolutionMode::tryFrom((string) config('tenancy.resolution'))) {
            return [];
        }

        if (! is_string($base) || '' === $base) {
            return [];
        }

        return ['^(.+\\.)?' . preg_quote(mb_strtolower($base), '#') . '$'];
    }
}
