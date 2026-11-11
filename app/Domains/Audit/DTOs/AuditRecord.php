<?php

declare(strict_types=1);

namespace App\Domains\Audit\DTOs;

use App\Domains\Audit\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;

/**
 * One action to record. The caller names everything an entry shows: the action from the catalogue, the record it was
 * done to and that record's name at the time (never a contact's personal data: a contact is labelled by a short id),
 * the assistant it belongs to, the whitelisted changes and context properties.
 *
 * The actor is left out when the action is the current user's: `AuditActorResolverInterface` decides it. A caller that
 * knows better (a sign-in listener, a job acting for someone) passes it.
 */
final readonly class AuditRecord
{
    /**
     * @param  array<string, mixed>  $properties  context, never a secret: a flow version, a role's permissions
     */
    public function __construct(
        public AuditAction $action,
        public ?Model $subject = null,
        public ?string $subjectLabel = null,
        public ?string $assistantId = null,
        public ?AuditChanges $changes = null,
        public array $properties = [],
        public ?AuditActor $actor = null,
    ) {
    }
}
