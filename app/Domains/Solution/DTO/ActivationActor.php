<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

use App\Domains\Solution\Enums\ActivationActorKind;

/**
 * Who asked for an activation change, as the journal keeps it. The Solution domain does not know Staff:
 * the caller passes the user's id and name.
 */
final readonly class ActivationActor
{
    /**
     * @param  string|null  $id  the staff user's id when `$kind` is User; never a foreign key, the user may be deleted later
     * @param  string|null  $label  name or email at the time of the action, or "console"
     */
    public function __construct(
        public ActivationActorKind $kind,
        public ?string $id = null,
        public ?string $label = null,
    ) {
    }

    public static function user(string $id, string $label): self
    {
        return new self(ActivationActorKind::User, $id, $label);
    }

    public static function console(): self
    {
        return new self(ActivationActorKind::Console, null, 'console');
    }

    public static function operator(string $label): self
    {
        return new self(ActivationActorKind::Operator, null, $label);
    }

    public static function system(string $reason): self
    {
        return new self(ActivationActorKind::System, null, $reason);
    }
}
