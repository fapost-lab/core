<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Exceptions\TenantNotResolvedException;
use App\Domains\Tenancy\Services\TenantContext;
use PHPUnit\Framework\TestCase;

final class TenantContextTest extends TestCase
{
    public function test_throws_when_not_resolved(): void
    {
        $context = new TenantContext();

        $this->expectException(TenantNotResolvedException::class);

        $context->get();
    }

    public function test_returns_tenant_after_set(): void
    {
        $context = new TenantContext();
        $tenant  = $this->createMock(TenantInterface::class);

        $context->set($tenant);

        $this->assertSame($tenant, $context->get());
        $this->assertTrue($context->isResolved());
    }

    public function test_reset_clears_context(): void
    {
        $context = new TenantContext();
        $tenant  = $this->createMock(TenantInterface::class);

        $context->set($tenant);
        $this->assertTrue($context->isResolved());

        $context->reset();

        $this->assertFalse($context->isResolved());
    }

    public function test_get_throws_after_reset(): void
    {
        $context = new TenantContext();
        $tenant  = $this->createMock(TenantInterface::class);

        $context->set($tenant);
        $context->reset();

        $this->expectException(TenantNotResolvedException::class);

        $context->get();
    }

    public function test_is_not_resolved_initially(): void
    {
        $context = new TenantContext();

        $this->assertFalse($context->isResolved());
    }
}
