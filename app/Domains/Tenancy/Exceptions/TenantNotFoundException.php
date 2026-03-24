<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

final class TenantNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Tenant with id [{$id}] not found.");
    }

    public static function forSlug(string $slug): self
    {
        return new self("Tenant with slug [{$slug}] not found.");
    }
}
