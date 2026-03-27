<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSwitcher;
use PHPUnit\Framework\TestCase;
use Spatie\Permission\PermissionRegistrar;

final class TenantProvisioningServiceTest extends TestCase
{
    public function test_throws_when_admin_email_empty(): void
    {
        $db      = $this->createMock(TenantDatabaseManagerInterface::class);
        $service = new TenantProvisioningService(
            $this->createMock(TenantRepositoryInterface::class),
            $db,
            new TenantSwitcher(
                $this->createMock(TenantContextInterface::class),
                $db,
                $this->createMock(PermissionRegistrar::class),
            ),
            new AclBootstrapService(),
        );

        $this->expectException(TenantProvisioningException::class);

        $service->provision('main', ' ', 'secret');
    }

    public function test_throws_when_admin_password_empty(): void
    {
        $db      = $this->createMock(TenantDatabaseManagerInterface::class);
        $service = new TenantProvisioningService(
            $this->createMock(TenantRepositoryInterface::class),
            $db,
            new TenantSwitcher(
                $this->createMock(TenantContextInterface::class),
                $db,
                $this->createMock(PermissionRegistrar::class),
            ),
            new AclBootstrapService(),
        );

        $this->expectException(TenantProvisioningException::class);

        $service->provision('main', 'a@b.test', '');
    }
}
