<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

final class TenantSwitcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_flushes_permission_cache_after_switch_and_after_restore(): void
    {
        $tenant              = Mockery::mock(TenantInterface::class);
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();

        $dbManager->shouldReceive('switchTo')->once()->with($tenant);
        $dbManager->shouldReceive('restore')->once();
        $permissionRegistrar->shouldReceive('forgetCachedPermissions')->twice();

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);

        $result = $switcher->runForTenant($tenant, fn (): string => 'ok');

        $this->assertEquals('ok', $result);
    }

    public function test_flushes_permission_cache_even_when_callback_throws(): void
    {
        $tenant              = Mockery::mock(TenantInterface::class);
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();

        $dbManager->shouldReceive('switchTo')->once()->with($tenant);
        $dbManager->shouldReceive('restore')->once();
        $permissionRegistrar->shouldReceive('forgetCachedPermissions')->twice();

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);

        $this->expectException(RuntimeException::class);

        $switcher->runForTenant($tenant, function (): never {
            throw new RuntimeException('fail');
        });
    }

    public function test_restores_previous_tenant_context(): void
    {
        $outerTenant         = Mockery::mock(TenantInterface::class);
        $innerTenant         = Mockery::mock(TenantInterface::class);
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();

        $dbManager->shouldReceive('switchTo')->twice();
        $dbManager->shouldReceive('restore')->twice();
        $permissionRegistrar->shouldReceive('forgetCachedPermissions');

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);

        $switcher->runForTenant($outerTenant, function () use ($switcher, $innerTenant, $context): void {
            $this->assertSame($context->get(), $context->get());

            $switcher->runForTenant($innerTenant, function () use ($context, $innerTenant): void {
                $this->assertSame($innerTenant, $context->get());
            });
        });

        $this->assertFalse($context->isResolved());
    }
}
