<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\FakeTenantSchemas;

/**
 * A real {@see TenantProvisioningService} on the real (transacted) landlord repository, whose
 * schema and tenant-switch ports are fakes, so the first admin persists on the tenant-migrated
 * default connection of {@see \Tests\Feature\FeatureTestCase}.
 */
trait BuildsProvisioningService
{
    protected FakeTenantSchemas $schemas;

    protected function provisioningService(
        ?TenantRepositoryInterface $repository = null,
        ?ChannelWebhookRegistryInterface $webhooks = null,
        int $leaseSeconds = 900,
        ?LoggerInterface $logger = null,
    ): TenantProvisioningService {
        $this->schemas ??= new FakeTenantSchemas();

        $context = Mockery::mock(TenantContextInterface::class);
        $context->shouldReceive('isResolved')->andReturn(false);
        $context->shouldReceive('set');
        $context->shouldReceive('reset');

        $permissions = Mockery::mock(PermissionRegistrar::class);
        $permissions->shouldReceive('clearPermissionsCollection');

        if (! $webhooks instanceof ChannelWebhookRegistryInterface) {
            $webhooks = Mockery::mock(ChannelWebhookRegistryInterface::class);
            $webhooks->shouldReceive('warmup');
        }

        return new TenantProvisioningService(
            $repository ?? new TenantRepository(),
            $this->schemas,
            new TenantSwitcher($context, $this->schemas, $permissions),
            new AclBootstrapService(),
            $webhooks,
            new TenantSlugPolicy(['webhook', 'www', 'api']),
            $logger ?? new NullLogger(),
            $leaseSeconds,
        );
    }
}
