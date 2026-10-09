<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Infrastructure\CoreTenantProvisioner;
use App\Domains\Tenancy\Infrastructure\FirstAdminCredentialsGuard;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Repositories\TenantRepository;
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
use Psr\Log\NullLogger;
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

    private TenantRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new TenantRepository();
        $this->hash       = Hash::make(self::PASSWORD);
    }

    public function test_the_container_resolves_the_contract_to_core_implementation(): void
    {
        $this->assertInstanceOf(CoreTenantProvisioner::class, $this->app->make(TenantProvisionerInterface::class));
    }

    public function test_it_provisions_a_tenant_and_its_first_admin(): void
    {
        config(['tenancy.resolution' => 'single']);

        $result = $this->provisioner($this->workingService())->provision($this->request('acme'));

        $this->assertSame('acme', $result->slug);
        $this->assertTrue(Str::isUuid($result->id));
        $this->assertSame(url('/admin/login'), $result->loginUrl);

        $user = User::query()->where('email', 'admin@acme.test')->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password), 'The hash must be stored as given, not hashed again.');
        $this->assertSame('Acme Admin', $user->name);

        $row = Tenant::on('landlord')->findOrFail($result->id);
        $this->assertSame(TenantStatus::Active, $row->status);
        $this->assertNull($row->provisioning_lease_until);
        $this->assertNotNull($row->schema_claimed_at);
    }

    public function test_an_empty_admin_name_falls_back_to_administrator(): void
    {
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

        $result = $this->provisioner($this->workingService())->provision($this->request('acme'));

        $this->assertSame('http://acme.example.test/admin/login', $result->loginUrl);
    }

    public function test_a_malformed_slug_is_slug_invalid(): void
    {
        $this->assertFailure(ProvisioningFailure::SlugInvalid, 'Not A Slug');
    }

    public function test_a_reserved_slug_is_slug_reserved(): void
    {
        $this->assertFailure(ProvisioningFailure::SlugReserved, 'webhook');
    }

    public function test_an_existing_slug_is_slug_taken_without_calling_the_service(): void
    {
        Tenant::on('landlord')->create(['slug' => 'acme', 'schema_name' => 'tenant_acme', 'status' => TenantStatus::Active, 'config' => []]);

        $this->assertFailure(ProvisioningFailure::SlugTaken, 'acme');
        $this->assertSame(1, Tenant::on('landlord')->where('slug', 'acme')->count());
    }

    public function test_missing_credentials_and_invalid_hashes_never_reach_the_service(): void
    {
        $database = Mockery::mock(TenantDatabaseManagerInterface::class);
        $database->shouldNotReceive('schemaExists');
        $database->shouldNotReceive('createSchema');
        $provisioner = $this->provisioner($this->service($database, Mockery::mock(TenantContextInterface::class)));
        $tenants     = Tenant::on('landlord')->count();

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

        $this->assertSame($tenants, Tenant::on('landlord')->count(), 'No row is reserved for rejected input.');
    }

    public function test_a_unique_violation_on_save_is_slug_taken(): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findBySlug')->once()->andReturnNull();
        $repository->shouldReceive('isSlugOrSchemaTaken')->once()->andReturnFalse();
        $repository->shouldReceive('reserve')->once()->andThrow(
            new UniqueConstraintViolationException('pgsql', 'insert into tenants', [], new RuntimeException('duplicate key')),
        );

        $database = Mockery::mock(TenantDatabaseManagerInterface::class);
        $database->shouldReceive('schemaExists')->andReturn(false);
        $database->shouldNotReceive('createSchema');

        $this->assertFailureWith(ProvisioningFailure::SlugTaken, $this->service($database, Mockery::mock(TenantContextInterface::class), $repository), 'acme', $repository);
    }

    /**
     * A failed run leaves a Pending row behind that holds the slug, so a plain retry gets SlugTaken; the
     * exception names the row, and TenantReservationInterface::provision() continues it.
     */
    public function test_a_failed_run_leaves_a_pending_row_named_by_the_exception(): void
    {
        $database = Mockery::mock(TenantDatabaseManagerInterface::class);
        $database->shouldReceive('schemaExists')->andReturn(false);
        $database->shouldReceive('createSchema')->once()->andThrow(new RuntimeException('disk full ' . self::PASSWORD));
        $service = $this->service($database, Mockery::mock(TenantContextInterface::class));

        try {
            $this->provisioner($service)->provision($this->request('acme'));
            $this->fail('Expected provisioning to fail.');
        } catch (TenantProvisioningFailedException $e) {
            $this->assertSame(ProvisioningFailure::Failed, $e->reason);
            $this->assertNotNull($e->tenantId);
            $this->assertNotNull($e->getPrevious());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $tenantId = $e->tenantId;
        }

        $row = Tenant::on('landlord')->findOrFail($tenantId);
        $this->assertSame(TenantStatus::Pending, $row->status);
        $this->assertSame('create_schema: ' . RuntimeException::class, $row->provisioning_error);
        $this->assertNotNull($row->provisioning_failed_at);
        $this->assertNull($row->provisioning_lease_until);

        $this->assertFailureWith(ProvisioningFailure::SlugTaken, $service, 'acme');
    }

    private function assertFailure(ProvisioningFailure $reason, string $slug): void
    {
        $this->assertFailureWith($reason, $this->workingService(), $slug);
    }

    private function assertFailureWith(ProvisioningFailure $reason, TenantProvisioningService $service, string $slug, ?TenantRepositoryInterface $repository = null): void
    {
        try {
            $this->provisioner($service, $repository)->provision($this->request($slug));
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

    private function provisioner(TenantProvisioningService $service, ?TenantRepositoryInterface $repository = null): CoreTenantProvisioner
    {
        return new CoreTenantProvisioner(
            $service,
            $repository ?? $this->repository,
            new TenantSlugPolicy(['webhook']),
            new FirstAdminCredentialsGuard($this->app->make(HashManager::class)),
        );
    }

    private function workingService(): TenantProvisioningService
    {
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

    private function service(TenantDatabaseManagerInterface $database, TenantContextInterface $context, ?TenantRepositoryInterface $repository = null): TenantProvisioningService
    {
        $permissions = Mockery::mock(PermissionRegistrar::class);
        $permissions->shouldReceive('clearPermissionsCollection');

        $webhooks = Mockery::mock(ChannelWebhookRegistryInterface::class);
        $webhooks->shouldReceive('warmup');

        return new TenantProvisioningService(
            $repository ?? $this->repository,
            $database,
            new TenantSwitcher($context, $database, $permissions),
            new AclBootstrapService(),
            $webhooks,
            new TenantSlugPolicy(['webhook']),
            new NullLogger(),
        );
    }
}
