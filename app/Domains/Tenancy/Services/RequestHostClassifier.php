<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\ValueObjects\RequestHost;
use Illuminate\Http\Request;

/**
 * Answers one question about a request: whose host is it.
 *
 * In `host` mode the base domain is the platform's, and so is every declared platform
 * subdomain (`tenancy.platform_subdomains`). Any other single DNS label in front of the base
 * domain names a tenant, and every other host (including `a.b.<base>`) is foreign. Comparison
 * ignores case, a port and a trailing dot. In `single` mode the host is not read at all:
 * every host belongs to the default tenant, which is how a one-tenant installation has
 * always behaved, including on the base domain.
 */
final readonly class RequestHostClassifier
{
    /**
     * @param  list<string>  $platformSubdomains  first-level labels of the base domain served as platform hosts
     */
    public function __construct(
        private TenancyResolutionMode $mode,
        private string $baseDomain,
        private ?string $defaultTenantSlug,
        private TenantSlugPolicy $slugPolicy,
        private array $platformSubdomains = [],
    ) {
    }

    public function classify(Request $request): RequestHost
    {
        return $this->classifyHost($request->getHost());
    }

    public function classifyHost(string $host): RequestHost
    {
        if (TenancyResolutionMode::Single === $this->mode) {
            if (null === $this->defaultTenantSlug || '' === $this->defaultTenantSlug) {
                throw new TenantNotFoundException('TENANT_SLUG is not configured. Set TENANT_SLUG in your .env file.');
            }

            return RequestHost::tenant($this->defaultTenantSlug);
        }

        $host = mb_rtrim(mb_strtolower((string) preg_replace('/:\d+$/', '', $host)), '.');
        $base = mb_rtrim(mb_strtolower($this->baseDomain), '.');

        if ('' === $host || '' === $base) {
            return RequestHost::foreign();
        }

        if ($host === $base) {
            return RequestHost::platform();
        }

        $suffix = '.' . $base;

        if (! str_ends_with($host, $suffix)) {
            return RequestHost::foreign();
        }

        $label = mb_substr($host, 0, -mb_strlen($suffix));

        // Only a direct child of the base domain names a tenant, and only a servable label.
        if (str_contains($label, '.') || ! $this->slugPolicy->isWellFormed($label)) {
            return RequestHost::foreign();
        }

        if (in_array($label, array_map(mb_strtolower(...), $this->platformSubdomains), true)) {
            return RequestHost::platform();
        }

        return RequestHost::tenant($label);
    }
}
