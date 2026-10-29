<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Events;

use DateTimeImmutable;

/**
 * An admin asked to delete the workspace; the removal starts at `startsAt` unless an admin cancels first. The staff
 * domain listens to tell every admin of the tenant. Carries what the mail names, so a listener reads no landlord row.
 */
final readonly class WorkspaceDeletionScheduled
{
    public function __construct(
        public string $tenantId,
        public string $tenantSlug,
        public string $requestedByName,
        public string $requestedByEmail,
        public DateTimeImmutable $requestedAt,
        public DateTimeImmutable $startsAt,
    ) {
    }
}
