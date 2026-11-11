<?php

declare(strict_types=1);

namespace App\Domains\Audit\DTOs;

use App\Domains\Audit\Enums\AuditActorKind;
use Illuminate\Database\Eloquent\Model;

/**
 * Who did what an entry records, as the entry stores it: the kind, the causer record (none for the system), a label
 * frozen at the time of the action (it outlives a renamed or deleted user) and context properties (for platform
 * support: which support entry and which operator).
 */
final readonly class AuditActor
{
    /**
     * @param  array<string, string>  $properties
     */
    public function __construct(
        public AuditActorKind $kind,
        public ?Model $causer = null,
        public ?string $label = null,
        public array $properties = [],
    ) {
    }

    /**
     * Not a signed-in person: a job, a command, the scheduler. The label names it when the caller knows it.
     */
    public static function system(?string $label = null): self
    {
        return new self(AuditActorKind::System, null, $label);
    }
}
