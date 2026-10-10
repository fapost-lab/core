<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Closure;
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

    public function test_drops_loaded_permissions_after_switch_and_after_restore(): void
    {
        $tenant              = $this->tenant('t1');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();

        $dbManager->shouldReceive('switchTo')->once()->with($tenant);
        $dbManager->shouldReceive('restore')->once();
        $permissionRegistrar->shouldReceive('clearPermissionsCollection')->twice();

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);

        $result = $switcher->runForTenant($tenant, fn (): string => 'ok');

        $this->assertEquals('ok', $result);
    }

    public function test_drops_loaded_permissions_even_when_callback_throws(): void
    {
        $tenant              = $this->tenant('t1');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();

        $dbManager->shouldReceive('switchTo')->once()->with($tenant);
        $dbManager->shouldReceive('restore')->once();
        $permissionRegistrar->shouldReceive('clearPermissionsCollection')->twice();

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar, 'perm');

        try {
            $switcher->runForTenant($tenant, function () use ($permissionRegistrar): never {
                $this->assertSame('perm.tenant.t1', $permissionRegistrar->cacheKey);

                throw new RuntimeException('fail');
            });
            $this->fail('The callback failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fail', $exception->getMessage());
        }

        $this->assertSame('perm', $permissionRegistrar->cacheKey);
    }

    public function test_resets_tenant_context_and_runs_hooks_even_when_restore_throws(): void
    {
        $tenant              = $this->tenant('t1');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();
        $hookRan             = false;

        $dbManager->shouldReceive('switchTo')->once()->with($tenant);
        $dbManager->shouldReceive('restore')->once()->andThrow(new RuntimeException('database went away'));
        $permissionRegistrar->shouldReceive('clearPermissionsCollection')->twice();

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);
        $switcher->registerRestoreHook(function () use (&$hookRan): void {
            $hookRan = true;
        });

        try {
            $switcher->runForTenant($tenant, fn (): string => 'ok');
            $this->fail('The restore failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database went away', $exception->getMessage());
        }

        $this->assertTrue($hookRan);
        $this->assertFalse($context->isResolved());
    }

    public function test_restores_previous_tenant_context(): void
    {
        $outerTenant         = $this->tenant('outer');
        $innerTenant         = $this->tenant('inner');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();

        $dbManager->shouldReceive('switchTo')->twice();
        $dbManager->shouldReceive('restore')->twice();
        $permissionRegistrar->shouldReceive('clearPermissionsCollection');

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);

        $switcher->runForTenant($outerTenant, function () use ($switcher, $innerTenant, $context): void {
            $this->assertSame($context->get(), $context->get());

            $switcher->runForTenant($innerTenant, function () use ($context, $innerTenant): void {
                $this->assertSame($innerTenant, $context->get());
            });
        });

        $this->assertFalse($context->isResolved());
    }

    public function test_each_tenant_reads_permissions_under_its_own_cache_key(): void
    {
        $outerTenant         = $this->tenant('outer');
        $innerTenant         = $this->tenant('inner');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();
        $keys                = [];

        $dbManager->shouldReceive('switchTo');
        $dbManager->shouldReceive('restore');
        $permissionRegistrar->shouldReceive('clearPermissionsCollection');
        $permissionRegistrar->shouldNotReceive('forgetCachedPermissions');

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar, 'perm');

        $switcher->runForTenant($outerTenant, function () use ($switcher, $innerTenant, $permissionRegistrar, &$keys): void {
            $keys[] = $permissionRegistrar->cacheKey;

            $switcher->runForTenant($innerTenant, function () use ($permissionRegistrar, &$keys): void {
                $keys[] = $permissionRegistrar->cacheKey;
            });

            $keys[] = $permissionRegistrar->cacheKey;
        });

        $keys[] = $permissionRegistrar->cacheKey;

        $this->assertSame(['perm.tenant.outer', 'perm.tenant.inner', 'perm.tenant.outer', 'perm'], $keys);
    }

    public function test_context_hook_enters_before_the_switch_and_exits_in_reverse_order_after_the_callback(): void
    {
        $tenant              = $this->tenant('t1');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();
        $log                 = [];

        $dbManager->shouldReceive('switchTo')->once()->andReturnUsing(function () use (&$log): void {
            $log[] = 'switch';
        });
        $dbManager->shouldReceive('restore')->once()->andReturnUsing(function () use (&$log): void {
            $log[] = 'restore';
        });
        $permissionRegistrar->shouldReceive('clearPermissionsCollection');

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);

        foreach (['a', 'b'] as $name) {
            $switcher->registerContextHook(function () use ($name, &$log, $context): Closure {
                $log[] = "enter {$name}";
                $this->assertFalse($context->isResolved());

                return function () use ($name, &$log): void {
                    $log[] = "exit {$name}";
                };
            });
        }

        $switcher->runForTenant($tenant, function () use (&$log): void {
            $log[] = 'callback';
        });

        $this->assertSame(['enter a', 'enter b', 'switch', 'callback', 'restore', 'exit b', 'exit a'], $log);
    }

    public function test_context_hook_exits_when_the_callback_throws_and_when_restore_throws(): void
    {
        $tenant              = $this->tenant('t1');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();
        $exits               = 0;

        $dbManager->shouldReceive('switchTo')->twice();
        $dbManager->shouldReceive('restore')->twice()->andReturnUsing(
            fn () => null,
            fn () => throw new RuntimeException('database went away'),
        );
        $permissionRegistrar->shouldReceive('clearPermissionsCollection');

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);
        $switcher->registerContextHook(function () use (&$exits): Closure {
            return function () use (&$exits): void {
                $exits++;
            };
        });

        try {
            $switcher->runForTenant($tenant, fn () => throw new RuntimeException('fail'));
            $this->fail('The callback failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fail', $exception->getMessage());
        }

        $this->assertSame(1, $exits);

        try {
            $switcher->runForTenant($tenant, fn (): string => 'ok');
            $this->fail('The restore failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database went away', $exception->getMessage());
        }

        $this->assertSame(2, $exits);
        $this->assertFalse($context->isResolved());
    }

    public function test_a_context_hook_that_fails_to_enter_puts_back_the_earlier_ones_and_switches_nothing(): void
    {
        $tenant              = $this->tenant('t1');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();
        $exited              = false;

        $dbManager->shouldNotReceive('switchTo');
        $dbManager->shouldNotReceive('restore');

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);
        $switcher->registerContextHook(function () use (&$exited): Closure {
            return function () use (&$exited): void {
                $exited = true;
            };
        });
        $switcher->registerContextHook(fn () => throw new RuntimeException('cannot enter'));

        try {
            $switcher->runForTenant($tenant, fn (): string => 'ok');
            $this->fail('The enter failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('cannot enter', $exception->getMessage());
        }

        $this->assertTrue($exited);
        $this->assertFalse($context->isResolved());
    }

    public function test_every_exit_runs_even_when_one_throws_and_the_first_failure_is_rethrown(): void
    {
        $tenant              = $this->tenant('t1');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();
        $ran                 = [];

        $dbManager->shouldReceive('switchTo')->once();
        $dbManager->shouldReceive('restore')->once();
        $permissionRegistrar->shouldReceive('clearPermissionsCollection');

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);
        $switcher->registerContextHook(function () use (&$ran): Closure {
            return function () use (&$ran): void {
                $ran[] = 'a';

                throw new RuntimeException('exit a failed');
            };
        });
        $switcher->registerContextHook(function () use (&$ran): Closure {
            return function () use (&$ran): void {
                $ran[] = 'b';

                throw new RuntimeException('exit b failed');
            };
        });

        try {
            $switcher->runForTenant($tenant, fn (): string => 'ok');
            $this->fail('The exit failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('exit b failed', $exception->getMessage());
        }

        $this->assertSame(['b', 'a'], $ran);
        $this->assertFalse($context->isResolved());
    }

    public function test_a_failing_undo_does_not_hide_the_entry_failure(): void
    {
        $tenant              = $this->tenant('t1');
        $dbManager           = Mockery::mock(TenantDatabaseManagerInterface::class);
        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $context             = new TenantContext();
        $ran                 = [];

        $dbManager->shouldNotReceive('switchTo');

        $switcher = new TenantSwitcher($context, $dbManager, $permissionRegistrar);
        $switcher->registerContextHook(function () use (&$ran): Closure {
            return function () use (&$ran): void {
                $ran[] = 'a';
            };
        });
        $switcher->registerContextHook(function () use (&$ran): Closure {
            return function () use (&$ran): void {
                $ran[] = 'b';

                throw new RuntimeException('undo b failed');
            };
        });
        $switcher->registerContextHook(fn () => throw new RuntimeException('cannot enter'));

        try {
            $switcher->runForTenant($tenant, fn (): string => 'ok');
            $this->fail('The entry failure must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('cannot enter', $exception->getMessage());
        }

        $this->assertSame(['b', 'a'], $ran);
    }

    private function tenant(string $id): TenantInterface
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('getId')->andReturn($id);

        return $tenant;
    }
}
