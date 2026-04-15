<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface DataAccessorRegistryInterface
{
    public function resolve(string $path, string $contactId, string $tenantId): mixed;
}
