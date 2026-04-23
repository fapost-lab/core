<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface TenantEventRepositoryInterface
{
    /**
     * @return list<string>
     */
    public function getEventNamesByTenant(string $tenantId): array;
}
