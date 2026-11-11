<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Tenancy\Events\WorkspaceDeletionCancelled;
use App\Domains\Tenancy\Events\WorkspaceDeletionScheduled;

/**
 * Records that an admin asked to delete the workspace, or cancelled the request. Tenancy announces both and depends on
 * no other domain, so the entry is written from its events. The request itself is a landlord row; the entry goes to the
 * tenant's schema in the same request, by the admin who acted (the current user). Fails closed like every staff action.
 */
final readonly class RecordWorkspaceDeletion
{
    public function __construct(
        private AuditTrailInterface $trail,
    ) {
    }

    public function handle(WorkspaceDeletionScheduled|WorkspaceDeletionCancelled $event): void
    {
        $this->trail->record(new AuditRecord(
            action: $event instanceof WorkspaceDeletionScheduled
                ? AuditAction::WorkspaceDeletionRequested
                : AuditAction::WorkspaceDeletionCancelled,
            subjectLabel: $event->tenantSlug,
            properties: ['starts_at' => $event->startsAt->format(DATE_ATOM)],
        ));
    }
}
