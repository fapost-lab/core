<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Events;

use DateTimeImmutable;

/**
 * An admin cancelled the scheduled deletion of the workspace. The staff domain listens to tell every admin of the
 * tenant who did it.
 */
final readonly class WorkspaceDeletionCancelled
{
    public function __construct(
        public string $tenantId,
        public string $tenantSlug,
        public string $requestedByName,
        public string $requestedByEmail,
        public DateTimeImmutable $requestedAt,
        public DateTimeImmutable $startsAt,
        public string $cancelledByName,
        public string $cancelledByEmail,
        public DateTimeImmutable $cancelledAt,
    ) {
    }
}
