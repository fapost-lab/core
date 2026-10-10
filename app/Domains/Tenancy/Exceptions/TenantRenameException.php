<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use App\Domains\Tenancy\ValueObjects\RenameProblem;
use RuntimeException;
use Throwable;

/**
 * A slug change was refused. A bad slug is {@see InvalidTenantSlugException} and an unknown tenant
 * {@see TenantNotFoundException}; this is the rest.
 */
final class TenantRenameException extends RuntimeException
{
    private function __construct(string $message, public readonly RenameProblem $problem, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function slugTaken(string $slug, ?Throwable $previous = null): self
    {
        return new self("Tenant slug [{$slug}] is already taken.", RenameProblem::SlugTaken, $previous);
    }

    public static function tenantPending(string $tenantId): self
    {
        return new self("Tenant [{$tenantId}] is pending and cannot be renamed.", RenameProblem::TenantPending);
    }
}
