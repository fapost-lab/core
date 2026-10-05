<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\HostTenantResolver;
use App\Domains\Tenancy\Services\RequestHostClassifier;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantRequestRunner;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\TenancyMiddleware;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Both tenant middleware over a real classifier and resolver in `host` mode,
 * with only the repository and the database switch mocked.
 */
final class ResolveTenantContextTest extends TestCase
{
    private TenantContext $context;

    private ResolveTenantContext $web;

    private TenancyMiddleware $required;

    /** @var Mockery\MockInterface&TenantRepositoryInterface */
    private $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $dbManager = Mockery::mock(TenantDatabaseManagerInterface::class);
        $dbManager->shouldReceive('switchTo');
        $dbManager->shouldReceive('restore');
        $registrar = Mockery::mock(PermissionRegistrar::class);
        $registrar->shouldReceive('clearPermissionsCollection');
        $bootstrap = Mockery::mock(CoreBootstrapInterface::class);
        $bootstrap->shouldReceive('boot');
        $bootstrap->shouldReceive('reset');

        $this->repository = Mockery::mock(TenantRepositoryInterface::class);
        $this->context    = new TenantContext();
        $policy           = new TenantSlugPolicy(['admin']);
        $classifier       = new RequestHostClassifier(TenancyResolutionMode::Host, 'fapost.test', 'main', $policy);
        $runner           = new TenantRequestRunner(
            new HostTenantResolver($this->repository, $classifier, $policy),
            new TenantSwitcher($this->context, $dbManager, $registrar),
            $bootstrap,
        );

        $this->web      = new ResolveTenantContext($classifier, $runner);
        $this->required = new TenancyMiddleware($this->context, $classifier, $runner);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_base_domain_continues_without_a_tenant(): void
    {
        $this->repository->shouldNotReceive('findBySlug');
        $seen = 'unset';

        $response = $this->web->handle(Request::create('http://fapost.test/'), function () use (&$seen): Response {
            $seen = $this->context->isResolved();

            return new Response('platform');
        });

        $this->assertSame('platform', $response->getContent());
        $this->assertFalse($seen);
    }

    public function test_tenant_host_runs_inside_that_tenant_and_leaves_no_context_behind(): void
    {
        $this->repository->shouldReceive('findBySlug')->once()->with('acme')->andReturn($this->activeTenant('acme'));
        $seen = null;

        $this->web->handle(Request::create('http://acme.fapost.test/'), function () use (&$seen): Response {
            $seen = $this->context->get()->getSlug();

            return new Response('ok');
        });

        $this->assertSame('acme', $seen);
        $this->assertFalse($this->context->isResolved());
    }

    public function test_foreign_host_is_not_found(): void
    {
        $this->repository->shouldNotReceive('findBySlug');

        $this->expectException(NotFoundHttpException::class);

        $this->web->handle(Request::create('http://example.com/'), fn (): Response => new Response('never'));
    }

    public function test_required_middleware_passes_through_when_a_tenant_is_already_active(): void
    {
        $this->context->set($this->activeTenant('acme'));
        $this->repository->shouldNotReceive('findBySlug');

        $response = $this->required->handle(Request::create('http://fapost.test/'), fn (): Response => new Response('ok'));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_required_middleware_enters_the_tenant_when_none_is_active_yet(): void
    {
        $this->repository->shouldReceive('findBySlug')->once()->with('acme')->andReturn($this->activeTenant('acme'));
        $seen = null;

        $this->required->handle(Request::create('http://acme.fapost.test/'), function () use (&$seen): Response {
            $seen = $this->context->get()->getSlug();

            return new Response('ok');
        });

        $this->assertSame('acme', $seen);
    }

    public function test_required_middleware_rejects_the_base_domain(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->required->handle(Request::create('http://fapost.test/'), fn (): Response => new Response('never'));
    }

    public function test_required_middleware_rejects_a_foreign_host(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->required->handle(Request::create('http://example.com/'), fn (): Response => new Response('never'));
    }

    private function activeTenant(string $slug): TenantInterface
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('isActive')->andReturn(true);
        $tenant->shouldReceive('getSlug')->andReturn($slug);
        $tenant->shouldReceive('getId')->andReturn($slug);

        return $tenant;
    }
}
