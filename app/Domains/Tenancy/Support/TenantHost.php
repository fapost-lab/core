<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

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
