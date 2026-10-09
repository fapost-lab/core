<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * Why a reservation was not released: the row exists but may not be deleted by a release.
 */
enum ReleaseRefusal: string
{
    case NotPending    = 'not_pending';
    case SchemaClaimed = 'schema_claimed';
    case InProgress    = 'in_progress';
}
