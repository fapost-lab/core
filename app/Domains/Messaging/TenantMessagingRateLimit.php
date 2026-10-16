<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use Illuminate\Database\QueryException;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * The per-chat send limit of the tenant in whose context the send runs.
 *
 * Read at send time, from the tenant's own `messaging_rate_limit` setting, and memoized per tenant id for the
 * life of the scope (a request or a job). Bound `scoped`, never as a singleton: a long-lived worker must not
 * carry one job's answer into the next, or a change in Tenant settings would wait for a restart.
 *
 * The memo is keyed by tenant id rather than leaning on the scoped {@see TenantSettings} instance, because
 * `TenantSwitcher::runForTenant()` does not drop scoped instances: that object may belong to another tenant
 * served earlier in the same scope. A fresh `new TenantSettings()` loads lazily from the connection that is
 * current, which is the tenant's own, so it is deliberately not resolved from the container.
 *
 * The configured value is only a platform fallback: it applies outside a tenant and to a tenant whose row is
 * absent or unusable; provisioning stores 30 in every tenant schema (ADR `adr-20261016-runtime-settings-in-database`).
 */
final class TenantMessagingRateLimit
{
    /** @var array<string, int> */
    private array $perTenant = [];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly int $defaultPerMinute,
    ) {
    }

    /**
     * Messages per minute allowed to one chat of one channel.
     */
    public function perMinute(): int
    {
        if (! $this->tenantContext->isResolved()) {
            return $this->defaultPerMinute;
        }

        $tenantId = (string) $this->tenantContext->get()->getId();

        return $this->perTenant[$tenantId] ??= $this->read();
    }

    /**
     * The current tenant's stored limit, or the platform default when it has none.
     *
     * An unreadable settings table is reported but never stops a send.
     */
    private function read(): int
    {
        try {
            $settings = new TenantSettings();
            $value    = $settings->messaging_rate_limit;

            // An absent row is filled in from the class default (30), not reported as missing; that default is
            // not the platform's. The flag lives on the class-wide SettingsConfig (shared, reset on every load),
            // so it is only meaningful read here, synchronously after the load above.
            if (in_array('messaging_rate_limit', $settings->settingsConfig()->getDefaultValueLoadedProperties(), true)) {
                return $this->defaultPerMinute;
            }
        } catch (MissingSettings) {
            // Defensive: unreachable while every TenantSettings property has a class default.
            return $this->defaultPerMinute;
        } catch (QueryException $exception) {
            report($exception);

            return $this->defaultPerMinute;
        }

        return $value >= 1 ? $value : $this->defaultPerMinute;
    }
}
