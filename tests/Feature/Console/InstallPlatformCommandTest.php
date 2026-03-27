<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\InstallPlatformCommand;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

final class InstallPlatformCommandTest extends TestCase
{
    public function test_fails_when_tenant_already_exists_and_does_not_provision(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getPdo')->once()->andReturn(new stdClass());

        DB::shouldReceive('connection')->once()->with('landlord')->andReturn($connection);
        Artisan::shouldReceive('call')->never();

        $this->mock(TenantRepositoryInterface::class, function ($mock): void {
            $mock->shouldReceive('existsAny')->once()->andReturn(true);
        });

        $databaseManager = Mockery::mock(TenantDatabaseManagerInterface::class);
        $databaseManager->shouldNotReceive('schemaExists');
        $databaseManager->shouldNotReceive('createSchema');
        $databaseManager->shouldNotReceive('runMigrations');

        $tenantSwitcher = new TenantSwitcher(
            Mockery::mock(TenantContextInterface::class),
            $databaseManager,
            Mockery::mock(PermissionRegistrar::class),
        );

        $this->app->instance(
            TenantProvisioningService::class,
            new TenantProvisioningService(
                Mockery::mock(TenantRepositoryInterface::class),
                $databaseManager,
                $tenantSwitcher,
                new AclBootstrapService(),
            ),
        );

        $command = $this->app->make(InstallPlatformCommand::class);
        $command->setLaravel($this->app);
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            '--tenant-slug'    => 'app',
            '--admin-email'    => 'admin@example.test',
            '--admin-password' => 'secret',
        ]);

        self::assertSame(InstallPlatformCommand::FAILURE, $exitCode);
        self::assertStringContainsString(
            'Only one tenant is allowed in self-hosted mode',
            $tester->getDisplay(),
        );
    }

    public function test_fails_when_landlord_migrations_fail_and_does_not_provision(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getPdo')->once()->andReturn(new stdClass());

        DB::shouldReceive('connection')->once()->with('landlord')->andReturn($connection);
        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', Mockery::on(fn (array $arguments): bool => 'database/migrations/landlord' === $arguments['--path']
                    && 'landlord' === $arguments['--database']
                    && true === $arguments['--force']))
            ->andReturn(1);

        $this->mock(TenantRepositoryInterface::class, function ($mock): void {
            $mock->shouldReceive('existsAny')->once()->andReturn(false);
        });

        $databaseManager = Mockery::mock(TenantDatabaseManagerInterface::class);
        $databaseManager->shouldNotReceive('schemaExists');
        $databaseManager->shouldNotReceive('createSchema');
        $databaseManager->shouldNotReceive('runMigrations');

        $tenantSwitcher = new TenantSwitcher(
            Mockery::mock(TenantContextInterface::class),
            $databaseManager,
            Mockery::mock(PermissionRegistrar::class),
        );

        $this->app->instance(
            TenantProvisioningService::class,
            new TenantProvisioningService(
                Mockery::mock(TenantRepositoryInterface::class),
                $databaseManager,
                $tenantSwitcher,
                new AclBootstrapService(),
            ),
        );

        $command = $this->app->make(InstallPlatformCommand::class);
        $command->setLaravel($this->app);
        $tester   = new CommandTester($command);
        $exitCode = $tester->execute([
            '--tenant-slug'    => 'app',
            '--admin-email'    => 'admin@example.test',
            '--admin-password' => 'secret',
        ]);

        self::assertSame(InstallPlatformCommand::FAILURE, $exitCode);
        self::assertStringContainsString(
            'Landlord migrations failed. Installation aborted.',
            $tester->getDisplay(),
        );
    }
}
