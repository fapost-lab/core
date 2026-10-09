<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\Platform\InstallPlatformCommand;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Psr\Log\NullLogger;
use Spatie\Permission\PermissionRegistrar;
use stdClass;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Feature\FeatureTestCase;

final class InstallPlatformCommandTest extends FeatureTestCase
{
    /**
     * Output of the most recently executed command, captured by {@see executeInstallCommand()}.
     */
    private string $lastCommandDisplay = '';
    public function test_fails_when_tenant_already_exists_and_does_not_provision(): void
    {
        $this->mockLandlordConnectionCheck();
        $this->mockTenantExistsAny(true);

        Artisan::shouldReceive('call')->never();

        $this->bindUninvolvedProvisioningService();

        $exitCode = $this->executeInstallCommand([
            '--tenant-slug'    => 'app',
            '--admin-email'    => 'admin@example.test',
            '--admin-password' => 'secret',
        ]);

        self::assertSame(InstallPlatformCommand::FAILURE, $exitCode);
        self::assertStringContainsString(
            'Only one tenant is allowed in self-hosted mode',
            $this->lastCommandDisplay,
        );
    }

    public function test_fails_when_landlord_migrations_fail_and_does_not_provision(): void
    {
        $this->mockLandlordConnectionCheck();
        $this->mockTenantExistsAny(false);

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', Mockery::on(fn (array $arguments): bool => ! array_key_exists('--path', $arguments)
                    && 'landlord' === $arguments['--database']
                    && true === $arguments['--force']))
            ->andReturn(1);

        $this->bindUninvolvedProvisioningService();

        $exitCode = $this->executeInstallCommand([
            '--tenant-slug'    => 'app',
            '--admin-email'    => 'admin@example.test',
            '--admin-password' => 'secret',
        ]);

        self::assertSame(InstallPlatformCommand::FAILURE, $exitCode);
        self::assertStringContainsString(
            'Platform migrations failed. Installation aborted.',
            $this->lastCommandDisplay,
        );
    }

    public function test_reads_admin_password_from_file_and_uses_it_for_provisioning(): void
    {
        $this->mockLandlordConnectionCheck();
        $this->mockTenantExistsAny(false);

        Artisan::shouldReceive('call')
            ->once()
            ->with('migrate', Mockery::on(fn (array $arguments): bool => ! array_key_exists('--path', $arguments)
                    && 'landlord' === $arguments['--database']
                    && true === $arguments['--force']))
            ->andReturn(InstallPlatformCommand::SUCCESS);

        $passwordFile = tempnam(sys_get_temp_dir(), 'admin-password-');
        self::assertNotFalse($passwordFile);
        file_put_contents($passwordFile, "s3cret\n");

        try {
            $this->bindProvisioningServiceThatSucceeds();

            $exitCode = $this->executeInstallCommand([
                '--tenant-slug'         => 'app',
                '--admin-email'         => 'admin@example.test',
                '--admin-password-file' => $passwordFile,
            ]);

            self::assertSame(InstallPlatformCommand::SUCCESS, $exitCode);
            self::assertStringContainsString('Schema: tenant_app', $this->lastCommandDisplay);

            // TenantProvisioningService is `final` and cannot be Mockery-mocked
            // while still satisfying InstallPlatformCommand's constructor
            // type-hint, so instead of asserting on a mocked provision() call
            // directly we drive the real service (via fully mocked ports) and
            // verify the exact file-derived password reached the persisted
            // admin user — proving the trailing newline was stripped and
            // nothing else was altered.
            $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
            self::assertTrue(Hash::check('s3cret', $admin->password));
        } finally {
            @unlink($passwordFile);
        }
    }

    public function test_fails_when_admin_password_file_does_not_exist(): void
    {
        $this->mockLandlordConnectionCheck();
        $this->mockTenantExistsAny(false);

        Artisan::shouldReceive('call')->never();

        $this->bindUninvolvedProvisioningService();

        $exitCode = $this->executeInstallCommand([
            '--tenant-slug'         => 'app',
            '--admin-email'         => 'admin@example.test',
            '--admin-password-file' => '/nonexistent/whatever',
        ]);

        self::assertSame(InstallPlatformCommand::FAILURE, $exitCode);
        self::assertStringContainsString(
            'Could not read the admin password',
            $this->lastCommandDisplay,
        );
    }

    public function test_fails_when_admin_password_file_is_empty(): void
    {
        $this->mockLandlordConnectionCheck();
        $this->mockTenantExistsAny(false);

        Artisan::shouldReceive('call')->never();

        $this->bindUninvolvedProvisioningService();

        $passwordFile = tempnam(sys_get_temp_dir(), 'admin-password-');
        self::assertNotFalse($passwordFile);
        file_put_contents($passwordFile, "\n");

        try {
            $exitCode = $this->executeInstallCommand([
                '--tenant-slug'         => 'app',
                '--admin-email'         => 'admin@example.test',
                '--admin-password-file' => $passwordFile,
            ]);

            self::assertSame(InstallPlatformCommand::FAILURE, $exitCode);
            self::assertStringContainsString('is empty', $this->lastCommandDisplay);
        } finally {
            @unlink($passwordFile);
        }
    }

    private function executeInstallCommand(array $input): int
    {
        $command = $this->app->make(InstallPlatformCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);

        $exitCode                 = $tester->execute($input);
        $this->lastCommandDisplay = $tester->getDisplay();

        return $exitCode;
    }

    private function mockLandlordConnectionCheck(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getPdo')->once()->andReturn(new stdClass());

        DB::shouldReceive('connection')->once()->with('landlord')->andReturn($connection);
    }

    private function mockTenantExistsAny(bool $exists): void
    {
        $this->mock(TenantRepositoryInterface::class, function ($mock) use ($exists): void {
            $mock->shouldReceive('existsAny')->once()->andReturn($exists);
        });
    }

    /**
     * Binds a real TenantProvisioningService (final, so it cannot be mocked
     * directly) wired to collaborators that must never be touched — used by
     * every scenario that fails before reaching the provisioning step.
     */
    private function bindUninvolvedProvisioningService(): void
    {
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
                Mockery::mock(ChannelWebhookRegistryInterface::class),
                new TenantSlugPolicy(),
                new NullLogger(),
            ),
        );
    }

    /**
     * Binds a real TenantProvisioningService wired to collaborators that make
     * a full successful provisioning run possible: the database/tenant-switch
     * ports are mocked no-ops (no real schema is created or switched to), so
     * the admin user actually persists through Eloquent on the already
     * tenant-migrated default connection provided by {@see FeatureTestCase}.
     */
    private function bindProvisioningServiceThatSucceeds(): void
    {
        // The real repository: the tenant row is reserved, leased and activated on the (transacted) landlord connection.
        $tenantRepository = new TenantRepository();

        $databaseManager = Mockery::mock(TenantDatabaseManagerInterface::class);
        $databaseManager->shouldReceive('schemaExists')->twice()->andReturn(false);
        $databaseManager->shouldReceive('createSchema')->once();
        $databaseManager->shouldReceive('switchTo')->once();
        $databaseManager->shouldReceive('runMigrations')->twice();
        $databaseManager->shouldReceive('restore')->once();

        $tenantContext = Mockery::mock(TenantContextInterface::class);
        $tenantContext->shouldReceive('isResolved')->once()->andReturn(false);
        $tenantContext->shouldReceive('set')->once();
        $tenantContext->shouldReceive('reset')->once();

        $permissionRegistrar = Mockery::mock(PermissionRegistrar::class);
        $permissionRegistrar->shouldReceive('clearPermissionsCollection')->twice();

        $channelWebhookRegistry = Mockery::mock(ChannelWebhookRegistryInterface::class);
        $channelWebhookRegistry->shouldReceive('warmup')->once();

        $tenantSwitcher = new TenantSwitcher($tenantContext, $databaseManager, $permissionRegistrar);

        $this->app->instance(
            TenantProvisioningService::class,
            new TenantProvisioningService(
                $tenantRepository,
                $databaseManager,
                $tenantSwitcher,
                new AclBootstrapService(),
                $channelWebhookRegistry,
                new TenantSlugPolicy(),
                new NullLogger(),
            ),
        );
    }
}
