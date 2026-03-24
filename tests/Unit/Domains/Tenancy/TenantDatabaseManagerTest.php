<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Database\TenantDatabaseManager;
use App\Domains\Tenancy\Exceptions\ConnectionStackEmptyException;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class TenantDatabaseManagerTest extends TestCase
{
    public function test_switch_and_restore_changes_default_connection(): void
    {
        $manager = $this->app->make(TenantDatabaseManager::class);
        $tenant  = $this->makeTenant('acme');

        $before = DB::getDefaultConnection();
        $manager->switchTo($tenant);
        $this->assertNotEquals($before, DB::getDefaultConnection());

        $manager->restore();
        $this->assertEquals($before, DB::getDefaultConnection());
    }

    public function test_restore_throws_on_empty_stack(): void
    {
        $manager = new TenantDatabaseManager();

        $this->expectException(ConnectionStackEmptyException::class);
        $manager->restore();
    }

    public function test_run_for_tenant_restores_on_exception(): void
    {
        $switcher = $this->app->make(TenantSwitcher::class);
        $tenant   = $this->makeTenant('acme');
        $before   = DB::getDefaultConnection();

        try {
            $switcher->runForTenant($tenant, function (): void {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertEquals($before, DB::getDefaultConnection());
    }

    public function test_nested_run_for_tenant_restores_correctly(): void
    {
        $switcher = $this->app->make(TenantSwitcher::class);
        $tenantA  = $this->makeTenant('alpha');
        $tenantB  = $this->makeTenant('beta');
        $before   = DB::getDefaultConnection();

        $tenantConn = (string) config('tenancy.tenant_connection');

        $switcher->runForTenant($tenantA, function () use ($switcher, $tenantB, $tenantConn): void {
            $afterA      = DB::getDefaultConnection();
            $searchPathA = (string) config("database.connections.{$tenantConn}.search_path");

            $switcher->runForTenant($tenantB, function () use ($afterA, $searchPathA, $tenantConn): void {
                $this->assertEquals($afterA, DB::getDefaultConnection());
                $this->assertNotEquals($searchPathA, (string) config("database.connections.{$tenantConn}.search_path"));
            });

            $this->assertEquals($afterA, DB::getDefaultConnection());
            $this->assertEquals($searchPathA, (string) config("database.connections.{$tenantConn}.search_path"));
        });

        $this->assertEquals($before, DB::getDefaultConnection());
    }

    public function test_migration_scope_labels(): void
    {
        $this->assertEquals('tenant', MigrationScope::tenant()->label);
        $this->assertEquals('features', MigrationScope::features()->label);
        $this->assertEquals('module:hr', MigrationScope::module('hr')->label);
    }

    public function test_migration_scope_paths(): void
    {
        $this->assertStringEndsWith(
            'migrations/tenant',
            MigrationScope::tenant()->path,
        );
        $this->assertStringEndsWith(
            'migrations/modules/hr',
            MigrationScope::module('hr')->path,
        );
    }

    private function makeTenant(string $slug): TenantInterface
    {
        return new class ($slug) implements TenantInterface {
            public function __construct(private string $slug)
            {
            }

            public function getId(): string
            {
                return 'test-id';
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getSchemaName(): string
            {
                return 'tenant_' . $this->slug;
            }

            public function isActive(): bool
            {
                return true;
            }

            public function getConfig(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        };
    }
}
