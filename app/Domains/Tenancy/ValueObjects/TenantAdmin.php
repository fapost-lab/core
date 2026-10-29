<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * An administrator of a tenant, as much of them as Tenancy keeps: who asked for or cancelled a deletion.
 */
final readonly class TenantAdmin
{
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
    ) {
    }
}
