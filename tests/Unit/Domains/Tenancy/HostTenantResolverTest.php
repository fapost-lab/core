<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Services\HostTenantResolver;
use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

final class HostTenantResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_resolves_the_tenant_named_by_the_host(): void
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('isActive')->andReturn(true);
        $repository = $this->repository();
        $repository->shouldReceive('findBySlug')->once()->with('acme')->andReturn($tenant);

        $result = $this->resolver($repository)->resolve(Request::create('http://acme.fapost.test/'));

        $this->assertSame($tenant, $result);
    }

    public function test_unknown_slug_is_not_found(): void
    {
        $repository = $this->repository();
        $repository->shouldReceive('findBySlug')->once()->with('ghost')->andReturn(null);

        $this->expectException(TenantNotFoundException::class);

        $this->resolver($repository)->resolve(Request::create('http://ghost.fapost.test/'));
    }

    public function test_inactive_tenant_is_not_active(): void
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('isActive')->andReturn(false);
        $repository = $this->repository();
        $repository->shouldReceive('findBySlug')->once()->with('acme')->andReturn($tenant);

        $this->expectException(TenantNotActiveException::class);

        $this->resolver($repository)->resolve(Request::create('http://acme.fapost.test/'));
    }

    public function test_reserved_slug_is_not_found_without_touching_the_database(): void
    {
        $repository = $this->repository();
        $repository->shouldNotReceive('findBySlug');

        $this->expectException(TenantNotFoundException::class);

        $this->resolver($repository)->resolve(Request::create('http://admin.fapost.test/'));
    }

    public function test_base_domain_names_no_tenant(): void
    {
        $repository = $this->repository();
        $repository->shouldNotReceive('findBySlug');

        $this->expectException(TenantNotFoundException::class);

        $this->resolver($repository)->resolve(Request::create('http://fapost.test/'));
    }

    public function test_foreign_host_names_no_tenant(): void
    {
        $repository = $this->repository();
        $repository->shouldNotReceive('findBySlug');

        $this->expectException(TenantNotFoundException::class);

        $this->resolver($repository)->resolve(Request::create('http://acme.example.com/'));
    }

    private function resolver(TenantRepositoryInterface $repository): HostTenantResolver
    {
        $policy = new TenantSlugPolicy(['admin', 'www']);

        return new HostTenantResolver(
            $repository,
            new RequestHostClassifier(TenancyResolutionMode::Host, 'fapost.test', 'main', $policy),
            $policy,
        );
    }

    /**
     * @return MockInterface&TenantRepositoryInterface
     */
    private function repository(): MockInterface
    {
        return Mockery::mock(TenantRepositoryInterface::class);
    }
}
