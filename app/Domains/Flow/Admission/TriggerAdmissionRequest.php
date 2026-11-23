<?php

declare(strict_types=1);

namespace App\Domains\Flow\Admission;

use App\Domains\Flow\Enums\FlowTriggerType;
use DateTimeImmutable;

/**
 * One new run a trigger fired, asking to start. The trigger's type is null when its row is gone (a publish removed it
 * after it fired); the run is asked about all the same.
 *
 * The unit key names the run to the operator's meter, the same on every retry of the same fire: a hash of the trigger id
 * and what fired it ({@see self::unitKeyFor()}), never the fire key itself, which can be another system's delivery id.
 */
final readonly class TriggerAdmissionRequest
{
    public function __construct(
        public string $tenantId,
        public string $flowId,
        public string $triggerId,
        public ?FlowTriggerType $triggerType,
        public string $unitKey,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * The meter's unit key for a trigger's fire: `run:` and the SHA-256 of `<trigger id>:<what fired it>`.
     */
    public static function unitKeyFor(string $triggerId, string $fire): string
    {
        return 'run:' . hash('sha256', mb_strtolower($triggerId) . ':' . $fire);
    }
}
