<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Middleware\RedirectTenantRootToAdmin;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

final class RedirectTenantRootToAdminTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_redirects_root_path_when_tenant_context_is_resolved(): void
    {
        $tenantContext = Mockery::mock(TenantContextInterface::class);
        $tenantContext->shouldReceive('isResolved')->once()->andReturn(true);

        $middleware = new RedirectTenantRootToAdmin($tenantContext);
        $request    = Request::create('/');

        $response = $middleware->handle($request, fn (): Response => new Response('ok'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin', $response->headers->get('Location'));
    }

    public function test_allows_request_when_path_is_not_root(): void
    {
        $tenantContext = Mockery::mock(TenantContextInterface::class);
        $tenantContext->shouldReceive('isResolved')->once()->andReturn(true);

        $middleware = new RedirectTenantRootToAdmin($tenantContext);
        $request    = Request::create('/login-check');

        $response = $middleware->handle($request, fn (): Response => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    public function test_allows_root_path_when_tenant_context_is_not_resolved(): void
    {
        $tenantContext = Mockery::mock(TenantContextInterface::class);
        $tenantContext->shouldReceive('isResolved')->once()->andReturn(false);

        $middleware = new RedirectTenantRootToAdmin($tenantContext);
        $request    = Request::create('/');

        $response = $middleware->handle($request, fn (): Response => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }
}
