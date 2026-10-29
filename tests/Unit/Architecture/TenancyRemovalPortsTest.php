<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Tenancy gains no outward dependencies: removing a tenant reaches channels, media, staff and the Solution cache through
 * ports in `App\Domains\Tenancy\Contracts` that those domains implement. The files of the removal and of the owner's
 * deletion request import nothing from another domain.
 */
final class TenancyRemovalPortsTest extends TestCase
{
    private const array FILES = [
        'Services/TenantDecommissioner.php',
        'Services/TenantCaches.php',
        'Services/TenantOwnCacheForgetter.php',
        'Services/WorkspaceDeletion.php',
        'Services/SupportAccessTokenStore.php',
        'Infrastructure/CoreTenantDecommissioner.php',
        'Jobs/DecommissionTenantJob.php',
        'Repositories/TenantDeletionRequestRepository.php',
        'Models/TenantDeletionRequest.php',
        'Events/WorkspaceDeletionScheduled.php',
        'Events/WorkspaceDeletionCancelled.php',
    ];

    public function test_the_removal_imports_no_other_domain(): void
    {
        $violations = [];

        foreach (self::FILES as $file) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Domains/Tenancy/' . $file);
            preg_match_all('/^use (App\\\\[^;]+);/m', $source, $matches);

            foreach ($matches[1] as $import) {
                if (! str_starts_with($import, 'App\\Domains\\Tenancy\\')) {
                    $violations[] = $file . ' imports ' . $import;
                }
            }
        }

        $this->assertSame([], $violations);
    }
}
