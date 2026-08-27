<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\MigrationScope;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class TenantsMigrateCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
    public function test_completes_successfully_when_no_active_tenants(): void
    {
        $this->mock(TenantRepositoryInterface::class, function ($mock): void {
            $mock->shouldReceive('findAllActive')->once()->andReturn([]);
        });

        $exitCode = Artisan::call('ops:tenants-migrate');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Migrated: 0 successful, 0 failed', Artisan::output());
    }

    public function test_runs_tenant_migrations_once_for_single_active_tenant(): void
    {
        $tenant = $this->tenantStub('acme', 'tenant_acme');

        $this->mock(TenantRepositoryInterface::class, function ($mock) use ($tenant): void {
            $mock->shouldReceive('findAllActive')->once()->andReturn([$tenant]);
        });

        $db = Mockery::mock(TenantDatabaseManagerInterface::class);
        $db->shouldReceive('switchTo')->once()->with($tenant);
        $db->shouldReceive('runMigrations')
            ->once()
            ->with(Mockery::on(fn (MigrationScope $scope): bool => 'tenant' === $scope->label));
        $db->shouldReceive('restore')->once();

        $this->app->instance(TenantDatabaseManagerInterface::class, $db);
        $this->app->forgetInstance(TenantSwitcher::class);

        $exitCode = Artisan::call('ops:tenants-migrate');

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('✔ acme', $output);
        $this->assertStringContainsString('Migrated: 1 successful, 0 failed', $output);
    }

    public function test_continues_when_one_tenant_migration_fails_and_returns_failure(): void
    {
        $first  = $this->tenantStub('fails', 'tenant_fails', '00000000-0000-0000-0000-000000000001');
        $second = $this->tenantStub('ok', 'tenant_ok', '00000000-0000-0000-0000-000000000002');

        $this->mock(TenantRepositoryInterface::class, function ($mock) use ($first, $second): void {
            $mock->shouldReceive('findAllActive')->once()->andReturn([$first, $second]);
        });

        $db = Mockery::mock(TenantDatabaseManagerInterface::class);
        $db->shouldReceive('switchTo')->twice();
        $db->shouldReceive('restore')->twice();

        $call = 0;
        $db->shouldReceive('runMigrations')
            ->twice()
            ->with(Mockery::on(fn (MigrationScope $scope): bool => 'tenant' === $scope->label))
            ->andReturnUsing(function () use (&$call): void {
                $call++;
                if (1 === $call) {
                    throw new RuntimeException('migration failed');
                }
            });

        $this->app->instance(TenantDatabaseManagerInterface::class, $db);
        $this->app->forgetInstance(TenantSwitcher::class);

        $exitCode = Artisan::call('ops:tenants-migrate');

        $this->assertSame(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('✘ fails: migration failed', $output);
        $this->assertStringContainsString('✔ ok', $output);
        $this->assertStringContainsString('Migrated: 1 successful, 1 failed', $output);
    }

    private function tenantStub(string $slug, string $schemaName, string $id = '00000000-0000-0000-0000-000000000099'): TenantInterface
    {
        return new readonly class ($id, $slug, $schemaName) implements TenantInterface {
            public function __construct(
                private string $id,
                private string $slug,
                private string $schemaName,
            ) {
            }

            public function getId(): string
            {
                return $this->id;
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getSchemaName(): string
            {
                return $this->schemaName;
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
