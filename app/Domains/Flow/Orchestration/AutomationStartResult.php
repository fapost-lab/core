<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\Admission\RefusalReason;
use App\Domains\Flow\Models\FlowSession;

/**
 * What {@see AutomationRunner::start()} did with a trigger's fire: started a new run, went on with the run the fire
 * already had, found no active definition to start, or was refused by run admission.
 */
final readonly class AutomationStartResult
{
    private function __construct(
        public ?FlowSession $session,
        public bool $continued,
        public ?RefusalReason $refusal,
    ) {
    }

    public static function started(FlowSession $session): self
    {
        return new self($session, false, null);
    }

    public static function continued(FlowSession $session): self
    {
        return new self($session, true, null);
    }

    public static function noActiveFlow(): self
    {
        return new self(null, false, null);
    }

    public static function refused(RefusalReason $reason): self
    {
        return new self(null, false, $reason);
    }

    public function isRefused(): bool
    {
        return null !== $this->refusal;
    }
}
