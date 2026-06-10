<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Services\ConfigTenantResolver;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

final class ConfigTenantResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
    public function test_resolves_tenant_by_slug_from_config(): void
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('isActive')->once()->andReturn(true);

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findBySlug')->once()->with('acme')->andReturn($tenant);

        $resolver = new ConfigTenantResolver($repository, 'acme');
        $result   = $resolver->resolve(Request::create('/'));

        $this->assertSame($tenant, $result);
    }

    public function test_throws_when_slug_not_configured(): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $resolver   = new ConfigTenantResolver($repository, null);

        $this->expectException(TenantNotFoundException::class);

        $resolver->resolve(Request::create('/'));
    }

    public function test_throws_when_tenant_not_found(): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findBySlug')->once()->with('unknown')->andReturn(null);

        $resolver = new ConfigTenantResolver($repository, 'unknown');

        $this->expectException(TenantNotFoundException::class);

        $resolver->resolve(Request::create('/'));
    }

    public function test_throws_when_tenant_not_active(): void
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('isActive')->once()->andReturn(false);

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findBySlug')->once()->with('inactive')->andReturn($tenant);

        $resolver = new ConfigTenantResolver($repository, 'inactive');

        $this->expectException(TenantNotActiveException::class);

        $resolver->resolve(Request::create('/'));
    }
}
