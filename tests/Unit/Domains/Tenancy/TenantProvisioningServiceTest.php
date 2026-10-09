<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Spatie\Permission\PermissionRegistrar;

final class TenantProvisioningServiceTest extends TestCase
{
    public function test_throws_when_admin_email_empty(): void
    {
        $this->expectException(TenantProvisioningException::class);

        $this->service()->provision('main', ' ', 'secret');
    }

    public function test_throws_when_admin_password_empty(): void
    {
        $this->expectException(TenantProvisioningException::class);

        $this->service()->provision('main', 'a@b.test', '');
    }

    /**
     * The slug is checked before credentials because it decides whether anything
     * may be created at all — a reserved name must fail even with a valid admin.
     */
    public function test_rejects_a_reserved_slug_before_touching_the_database(): void
    {
        $repository = $this->createMock(TenantRepositoryInterface::class);
        $repository->expects($this->never())->method('save');

        $database = $this->createMock(TenantDatabaseManagerInterface::class);
        $database->expects($this->never())->method('createSchema');

        $this->expectException(InvalidTenantSlugException::class);

        $this->service($repository, $database)->provision('webhook', 'a@b.test', 'secret');
    }

    public function test_rejects_a_too_long_slug_before_touching_the_database(): void
    {
        $repository = $this->createMock(TenantRepositoryInterface::class);
        $repository->expects($this->never())->method('save');

        $database = $this->createMock(TenantDatabaseManagerInterface::class);
        $database->expects($this->never())->method('schemaExists');
        $database->expects($this->never())->method('createSchema');

        $this->expectException(InvalidTenantSlugException::class);

        $this->service($repository, $database)->provision(str_repeat('a', 57), 'a@b.test', 'secret');
    }

    public function test_rejects_a_malformed_slug(): void
    {
        $this->expectException(InvalidTenantSlugException::class);

        $this->service()->provision('Not A Slug', 'a@b.test', 'secret');
    }

    private function service(
        ?TenantRepositoryInterface $repository = null,
        ?TenantDatabaseManagerInterface $database = null,
    ): TenantProvisioningService {
        $database ??= $this->createMock(TenantDatabaseManagerInterface::class);

        return new TenantProvisioningService(
            $repository ?? $this->createMock(TenantRepositoryInterface::class),
            $database,
            new TenantSwitcher(
                $this->createMock(TenantContextInterface::class),
                $database,
                $this->createMock(PermissionRegistrar::class),
            ),
            new AclBootstrapService(),
            $this->createMock(ChannelWebhookRegistryInterface::class),
            new TenantSlugPolicy(['webhook', 'www', 'api']),
            new NullLogger(),
        );
    }
}
