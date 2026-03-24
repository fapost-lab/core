<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\TenantNotResolvedException;

interface TenantContextInterface
{
    public function set(TenantInterface $tenant): void;

    /**
     * @throws TenantNotResolvedException
     */
    public function get(): TenantInterface;

    public function isResolved(): bool;

    public function runForTenant(TenantInterface $tenant, callable $callback): mixed;
}
