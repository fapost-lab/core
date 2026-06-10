<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifies the DomainServiceProvider wiring: a container-resolved TenantSwitcher
 * must reset CoreBootstrap's memoized tenant id when the tenant scope is restored,
 * so a stale memo can never suppress a re-boot for a different tenant.
 */
final class CoreBootstrapResetHookTest extends TestCase
{
    public function test_run_for_tenant_resets_core_bootstrap_on_restore(): void
    {
        $this->mock(CoreBootstrapInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reset')->once();
        });

        $this->mock(TenantDatabaseManagerInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('switchTo')->once();
            $mock->shouldReceive('restore')->once();
        });

        $switcher = $this->app->make(TenantSwitcher::class);

        $switcher->runForTenant(
            new RuntimeTenant(id: 'tenant-1', schemaName: 'tenant_test'),
            static fn (): null => null,
        );
    }

    public function test_core_bootstrap_reset_runs_even_when_callback_throws(): void
    {
        $this->mock(CoreBootstrapInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reset')->once();
        });

        $this->mock(TenantDatabaseManagerInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('switchTo')->once();
            $mock->shouldReceive('restore')->once();
        });

        $switcher = $this->app->make(TenantSwitcher::class);

        try {
            $switcher->runForTenant(
                new RuntimeTenant(id: 'tenant-1', schemaName: 'tenant_test'),
                static function (): never {
                    throw new RuntimeException('boom');
                },
            );
            $this->fail('Expected the callback exception to propagate.');
        } catch (RuntimeException) {
            // expected — the restore hook must already have fired.
        }
    }
}
