<?php

declare(strict_types=1);

namespace App\Domains\Audit\Models;

use App\Domains\Audit\Enums\AuditActorKind;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Spatie\Activitylog\Models\Activity;

/**
 * One entry of the tenant's audit log: the package's activity model on a ULID key, with the actor and subject frozen
 * at the time of the action. Entries are written once, by `AuditTrail`, and never updated.
 *
 * `description` holds the action's machine key ({@see \App\Domains\Audit\Enums\AuditAction}), `log_name` its area.
 * `subject_type` and `causer_type` are class names (Core has no morph map); nothing filters by them.
 *
 * @property string $id
 * @property AuditActorKind $actor_kind
 * @property string|null $actor_label
 * @property string|null $subject_label
 * @property string|null $assistant_id
 * @property \Illuminate\Support\Carbon $created_at
 */
final class ActivityEntry extends Activity
{
    use HasUlidPrimaryKey;

    public const ?string UPDATED_AT = null;

    /** @var string */
    protected $table = 'activity_log';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'actor_kind' => AuditActorKind::class,
            'created_at' => 'datetime',
        ];
    }
}
