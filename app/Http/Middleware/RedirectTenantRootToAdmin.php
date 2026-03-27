<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RedirectTenantRootToAdmin
{
    public function __construct(
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->tenantContext->isResolved() && '/' === $request->getPathInfo()) {
            return new RedirectResponse('/admin');
        }

        return $next($request);
    }
}
