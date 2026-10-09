<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Exceptions\UnsupportedHostModeConfigurationException;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Illuminate\Contracts\Config\Repository;

/**
 * What `host` mode needs from the rest of the deployment, checked against configuration.
 *
 * Two settings are about sessions. Platform pages (the base domain, a declared platform
 * subdomain) start a session with no tenant, so the `database` driver, whose `sessions` table lives
 * in a tenant schema, fails there. A `SESSION_DOMAIN` that spans subdomains would hand one tenant's
 * session cookie to every other tenant host, so cookies stay host-only. Both are errors.
 *
 * The third is about webhook ingress: the hosts of `WEBHOOK_BASE_URL` and of the gateway receive
 * requests too, and TrustHosts answers 400 to any host outside the base domain. That one is
 * advice, not an error: it does not stop the panels from working.
 */
final readonly class HostModeDeploymentCheck
{
    public function __construct(private Repository $config)
    {
    }

    public function applies(): bool
    {
        return TenancyResolutionMode::Host === TenancyResolutionMode::tryFrom((string) $this->config->get('tenancy.resolution'));
    }

    /**
     * Settings that stop `host` mode from working or isolating tenants.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        if (! $this->applies()) {
            return [];
        }

        $errors = [];

        if ('database' === $this->config->get('session.driver')) {
            $errors[] = 'SESSION_DRIVER=database keeps sessions in a tenant schema, which platform pages do not have; use redis, file or cookie.';
        }

        $domain = $this->config->get('session.domain');

        if (is_string($domain) && '' !== $domain) {
            $errors[] = "SESSION_DOMAIN={$domain} would share session cookies between tenant hosts; leave it unset so cookies stay host-only.";
        }

        return $errors;
    }

    /**
     * Ingress hosts that the application would refuse.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        if (! $this->applies()) {
            return [];
        }

        $base = mb_strtolower((string) $this->config->get('tenancy.base_domain'));

        if ('' === $base) {
            return [];
        }

        $warnings = [];

        foreach (['webhook.base_url' => 'WEBHOOK_BASE_URL', 'webhook.ingress.gateway_url' => 'WEBHOOK_GATEWAY_URL'] as $key => $variable) {
            $value = $this->config->get($key);
            $host  = is_string($value) && '' !== $value ? parse_url($value, PHP_URL_HOST) : null;

            if (! is_string($host)) {
                continue;
            }

            $host = mb_strtolower($host);

            if ($host !== $base && ! str_ends_with($host, '.' . $base)) {
                $warnings[] = "{$variable} points at {$host}, outside the base domain {$base}; the application answers such a host with 400.";
            }
        }

        return $warnings;
    }

    /**
     * @throws UnsupportedHostModeConfigurationException
     */
    public function assertWorkable(): void
    {
        $errors = $this->errors();

        if ([] !== $errors) {
            throw UnsupportedHostModeConfigurationException::forProblems($errors);
        }
    }
}
