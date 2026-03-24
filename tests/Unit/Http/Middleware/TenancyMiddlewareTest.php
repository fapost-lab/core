<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantResolverInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Http\Middleware\TenancyMiddleware;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class TenancyMiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_sets_tenant_context_and_switches_db(): void
    {
        $tenant    = Mockery::mock(TenantInterface::class);
        $resolver  = Mockery::mock(TenantResolverInterface::class);
        $dbManager = Mockery::mock(TenantDatabaseManagerInterface::class);
        $bootstrap = Mockery::mock(CoreBootstrapInterface::class);
        $context   = new TenantContext();
        $switcher  = new TenantSwitcher($context, $dbManager);

        $request = Request::create('/test');

        $resolver->shouldReceive('resolve')->once()->with($request)->andReturn($tenant);
        $dbManager->shouldReceive('switchTo')->once()->with($tenant);
        $dbManager->shouldReceive('restore')->once();
        $bootstrap->shouldReceive('boot')->once();
        $bootstrap->shouldReceive('reset')->once();

        $middleware = new TenancyMiddleware($resolver, $switcher, $bootstrap);
        $response   = $middleware->handle($request, fn (): Response => new Response('ok'));

        $this->assertEquals('ok', $response->getContent());
        $this->assertFalse($context->isResolved());
    }

    public function test_resets_bootstrap_even_on_exception(): void
    {
        $tenant    = Mockery::mock(TenantInterface::class);
        $resolver  = Mockery::mock(TenantResolverInterface::class);
        $dbManager = Mockery::mock(TenantDatabaseManagerInterface::class);
        $bootstrap = Mockery::mock(CoreBootstrapInterface::class);
        $context   = new TenantContext();
        $switcher  = new TenantSwitcher($context, $dbManager);

        $request = Request::create('/test');

        $resolver->shouldReceive('resolve')->twice()->with($request)->andReturn($tenant);
        $dbManager->shouldReceive('switchTo')->twice()->with($tenant);
        $dbManager->shouldReceive('restore')->twice();
        $bootstrap->shouldReceive('boot')->twice();
        $bootstrap->shouldReceive('reset')->twice();

        $middleware = new TenancyMiddleware($resolver, $switcher, $bootstrap);

        try {
            $middleware->handle($request, function (): Response {
                throw new RuntimeException('test');
            });
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException) {
            $response = $middleware->handle($request, fn (): Response => new Response('ok'));
            $this->assertEquals('ok', $response->getContent());
        }

        $this->assertFalse($context->isResolved());
    }
}
