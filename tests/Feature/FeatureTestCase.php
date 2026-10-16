<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Support\TenantHost;
use Fapost\Testing\Concerns\PreparesCoreDatabase;
use Tests\TestCase;

/**
 * Base class for feature tests that require tenant context.
 *
 * Runs landlord migrations on the landlord connection and seeds a test tenant
 * so that TenancyMiddleware can resolve it during HTTP tests. The database
 * preparation is {@see PreparesCoreDatabase}, shared with the Solution testing kit.
 */
abstract class FeatureTestCase extends TestCase
{
    use PreparesCoreDatabase;

    /**
     * Absolute URL for a panel path.
     *
     * The admin and assistant panels are bound to the tenant host, so a bare
     * path resolves against the base domain and answers 404. Tests that exercise
     * a panel go through here rather than repeating the host.
     */
    protected function panelUrl(string $path): string
    {
        $host = TenantHost::forDefaultTenant();

        if (null === $host) {
            return $path;
        }

        return 'http://' . $host . '/' . mb_ltrim($path, '/');
    }
}
