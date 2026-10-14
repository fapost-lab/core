<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

use App\Domains\Solution\Enums\ActivationAction;
use App\Domains\Solution\Enums\ActivationActorKind;
use DateTimeInterface;

/**
 * One entry of the activation journal.
 */
final readonly class SolutionActivationEvent
{
    public function __construct(
        public string $solutionId,
        public ActivationAction $action,
        public ActivationActorKind $actorKind,
        public ?string $actorId,
        public ?string $actorLabel,
        public ?string $reason,
        public DateTimeInterface $occurredAt,
    ) {
    }
}
