<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\Admission\RefusalReason;
use App\Domains\Flow\Models\FlowSession;

/**
 * What {@see AutomationRunner::start()} did with a trigger's fire: started a new run, went on with the run the fire
 * already had, found no active definition to start, found the flow switched off, or was refused by run admission.
 */
final readonly class AutomationStartResult
{
    private function __construct(
        public ?FlowSession $session,
        public bool $continued,
        public ?RefusalReason $refusal,
        public bool $flowOff = false,
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

    /**
     * The flow is switched off in the flows list: the fire starts no run, and run admission is not asked.
     */
    public static function flowOff(): self
    {
        return new self(null, false, null, true);
    }

    public static function refused(RefusalReason $reason): self
    {
        return new self(null, false, $reason);
    }

    public function isRefused(): bool
    {
        return null !== $this->refusal;
    }

    public function isFlowOff(): bool
    {
        return $this->flowOff;
    }
}
