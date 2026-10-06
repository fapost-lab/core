<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Infrastructure\CoreTenantProvisioner;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Tenancy\Contracts\TenantProvisionerInterface;
use Fapost\Foundation\Tenancy\DTO\ProvisionTenant;
use Fapost\Foundation\Tenancy\Enums\ProvisioningFailure;
use Fapost\Foundation\Tenancy\Exceptions\TenantProvisioningFailedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\FeatureTestCase;

/**
 * The provisioning contract end to end: a real {@see TenantProvisioningService} whose database and
 * tenant-switch ports are mocked no-ops (as in InstallPlatformCommandTest), so the first admin
 * really persists through Eloquent on the tenant-migrated connection.
 */
final class CoreTenantProvisionerTest extends FeatureTestCase
{
    private const string PASSWORD = 'S3cret-p@ss-value';

    private string $hash;

    /** @var MockInterface&TenantRepositoryInterface */
    private MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(TenantRepositoryInterface::class);
        $this->hash       = Hash::make(self::PASSWORD);
    }

    public function test_the_container_resolves_the_contract_to_core_implementation(): void
    {
        $this->assertInstanceOf(CoreTenantProvisioner::class, $this->app->make(TenantProvisionerInterface::class));
    }

    public function test_it_provisions_a_tenant_and_its_first_admin(): void
    {
        config(['tenancy.resolution' => 'single']);
        $this->repository->shouldReceive('findBySlug')->once()->with('acme')->andReturnNull();

        $result = $this->provisioner($this->workingService())->provision($this->request('acme'));

        $this->assertSame('acme', $result->slug);
        $this->assertTrue(Str::isUlid($result->id));
        $this->assertSame(url('/admin/login'), $result->loginUrl);

        $user = User::query()->where('email', 'admin@acme.test')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password), 'The hash must be stored as given, not hashed again.');
        $this->assertSame('Acme Admin', $user->name);
    }

    public function test_an_empty_admin_name_falls_back_to_administrator(): void
    {
        $this->repository->shouldReceive('findBySlug')->once()->andReturnNull();

        $this->provisioner($this->workingService())->provision(new ProvisionTenant('acme', 'admin@acme.test', ' ', $this->hash));

        $this->assertSame('Administrator', User::query()->where('email', 'admin@acme.test')->firstOrFail()->name);
    }

    public function test_the_login_url_names_the_tenant_host_in_host_mode(): void
    {
        config([
            'tenancy.resolution'  => 'host',
            'tenancy.base_domain' => 'example.test',
            'app.url'             => 'http://localhost',
        ]);
        $this->repository->shouldReceive('findBySlug')->once()->andReturnNull();

        $result = $this->provisioner($this->workingService())->provision($this->request('acme'));

        $this->assertSame('http://acme.example.test/admin/login', $result->loginUrl);
    }

    public function test_a_malformed_slug_is_slug_invalid(): void
    {
        $this->repository->shouldNotReceive('findBySlug');

        $this->assertFailure(ProvisioningFailure::SlugInvalid, 'Not A Slug');
    }

    public function test_a_reserved_slug_is_slug_reserved(): void
    {
        $this->repository->shouldNotReceive('findBySlug');

        $this->assertFailure(ProvisioningFailure::SlugReserved, 'webhook');
    }

    public function test_an_existing_slug_is_slug_taken_without_calling_the_service(): void
    {
        $this->repository->shouldReceive('findBySlug')->once()->with('acme')->andReturn(new Tenant(['slug' => 'acme']));

        $this->assertFailure(ProvisioningFailure::SlugTaken, 'acme');
    }

    public function test_missing_credentials_and_invalid_hashes_never_reach_the_service(): void
    {
        $this->repository->shouldReceive('findBySlug')->andReturnNull();
        $this->repository->shouldNotReceive('save');

        $database = Mockery::mock(TenantDatabaseManagerInterface::class);
        $database->shouldNotReceive('schemaExists');
        $database->shouldNotReceive('createSchema');
        $provisioner = $this->provisioner($this->service($database, Mockery::mock(TenantContextInterface::class)));

        $argon = Hash::driver('argon2id')->make(self::PASSWORD);

        $cases = [
            [ProvisioningFailure::AdminCredentialsMissing, new ProvisionTenant('acme', ' ', 'Admin', $this->hash)],
            [ProvisioningFailure::AdminCredentialsMissing, new ProvisionTenant('acme', 'admin@acme.test', 'Admin', '')],
            [ProvisioningFailure::AdminPasswordHashInvalid, new ProvisionTenant('acme', 'admin@acme.test', 'Admin', self::PASSWORD)],
            [ProvisioningFailure::AdminPasswordHashInvalid, new ProvisionTenant('acme', 'admin@acme.test', 'Admin', md5(self::PASSWORD))],
            [ProvisioningFailure::AdminPasswordHashInvalid, new ProvisionTenant('acme', 'admin@acme.test', 'Admin', $argon)],
        ];

        foreach ($cases as [$reason, $request]) {
            try {
                $provisioner->provision($request);
                $this->fail('Expected provisioning to fail.');
            } catch (TenantProvisioningFailedException $e) {
                $this->assertSame($reason, $e->reason);
                $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            }
        }
    }

    public function test_a_unique_violation_on_save_is_slug_taken(): void
    {
        $this->repository->shouldReceive('findBySlug')->once()->andReturnNull();
        $this->repository->shouldReceive('save')->once()->andThrow(
            new UniqueConstraintViolationException('pgsql', 'insert into tenants', [], new RuntimeException('duplicate key')),
        );

        $database = Mockery::mock(TenantDatabaseManagerInterface::class);
        $database->shouldReceive('schemaExists')->andReturn(false);
        $database->shouldNotReceive('createSchema');

        $this->assertFailureWith(ProvisioningFailure::SlugTaken, $this->service($database, Mockery::mock(TenantContextInterface::class)), 'acme');
    }

    /**
     * Documents the known limit: a failed run leaves an inactive row behind, so the slug stays taken
     * until it is cleaned up. Callers must not retry automatically.
     */
    public function test_a_retry_after_a_failed_run_gets_slug_taken(): void
    {
        $rows = [];
        $this->repository->shouldReceive('findBySlug')->andReturnUsing(function (string $slug) use (&$rows): ?TenantInterface {
            return $rows[$slug] ?? null;
        });
        $this->repository->shouldReceive('save')->andReturnUsing(static function (TenantInterface $tenant) use (&$rows): void {
            $rows[$tenant->getSlug()] = $tenant;
        });

        $database = Mockery::mock(TenantDatabaseManagerInterface::class);
        $database->shouldReceive('schemaExists')->andReturn(false);
        $database->shouldReceive('createSchema')->once()->andThrow(new RuntimeException('disk full'));
        $service = $this->service($database, Mockery::mock(TenantContextInterface::class));

        $this->assertFailureWith(ProvisioningFailure::Failed, $service, 'acme');
        $this->assertFailureWith(ProvisioningFailure::SlugTaken, $service, 'acme');
    }

    public function test_a_failing_step_is_failed_with_the_original_exception_and_no_password(): void
    {
        $this->repository->shouldReceive('findBySlug')->once()->andReturnNull();
        $this->repository->shouldReceive('save')->twice();

        $database = Mockery::mock(TenantDatabaseManagerInterface::class);
        $database->shouldReceive('schemaExists')->andReturn(false);
        $database->shouldReceive('createSchema')->andThrow(new RuntimeException('disk full ' . self::PASSWORD));

        $service = $this->service($database, Mockery::mock(TenantContextInterface::class));

        try {
            $this->provisioner($service)->provision($this->request('acme'));
            $this->fail('Expected provisioning to fail.');
        } catch (TenantProvisioningFailedException $e) {
            $this->assertSame(ProvisioningFailure::Failed, $e->reason);
            $this->assertNotNull($e->getPrevious());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    private function assertFailure(ProvisioningFailure $reason, string $slug): void
    {
        $this->assertFailureWith($reason, $this->workingService(), $slug);
    }

    private function assertFailureWith(ProvisioningFailure $reason, TenantProvisioningService $service, string $slug): void
    {
        try {
            $this->provisioner($service)->provision($this->request($slug));
            $this->fail('Expected provisioning to fail.');
        } catch (TenantProvisioningFailedException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    private function request(string $slug): ProvisionTenant
    {
        return new ProvisionTenant($slug, 'admin@acme.test', 'Acme Admin', $this->hash);
    }

    private function provisioner(TenantProvisioningService $service): CoreTenantProvisioner
    {
        return new CoreTenantProvisioner($service, $this->repository, new TenantSlugPolicy(['webhook']), $this->app->make(HashManager::class));
    }

    private function workingService(): TenantProvisioningService
    {
        $this->repository->shouldReceive('save')->andReturnUsing(static function (TenantInterface $tenant): void {
            if ($tenant instanceof Tenant && null === $tenant->id) {
                $tenant->id = (string) Str::ulid();
            }
        });

        $database = Mockery::mock(TenantDatabaseManagerInterface::class);
        $database->shouldReceive('schemaExists')->andReturn(false);
        $database->shouldReceive('createSchema');
        $database->shouldReceive('switchTo');
        $database->shouldReceive('runMigrations');
        $database->shouldReceive('restore');

        $context = Mockery::mock(TenantContextInterface::class);
        $context->shouldReceive('isResolved')->andReturn(false);
        $context->shouldReceive('set');
        $context->shouldReceive('reset');

        return $this->service($database, $context);
    }

    private function service(TenantDatabaseManagerInterface $database, TenantContextInterface $context): TenantProvisioningService
    {
        $permissions = Mockery::mock(PermissionRegistrar::class);
        $permissions->shouldReceive('clearPermissionsCollection');

        $webhooks = Mockery::mock(ChannelWebhookRegistryInterface::class);
        $webhooks->shouldReceive('warmup');

        return new TenantProvisioningService(
            $this->repository,
            $database,
            new TenantSwitcher($context, $database, $permissions),
            new AclBootstrapService(),
            $webhooks,
            new TenantSlugPolicy(['webhook']),
        );
    }
}
