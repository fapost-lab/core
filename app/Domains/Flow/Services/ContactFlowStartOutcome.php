<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Models\FlowSession;
use LogicException;

/**
 * The result of {@see ContactFlowStarter::start()}: the status, with the busy reason or the started session.
 */
final readonly class ContactFlowStartOutcome
{
    private function __construct(
        public ContactFlowStartStatus $status,
        public ?string $busyReason = null,
        public ?FlowSession $session = null,
        public int $endedStale = 0,
    ) {
    }

    public static function of(ContactFlowStartStatus $status): self
    {
        return new self($status);
    }

    public static function busy(?string $reason): self
    {
        return new self(ContactFlowStartStatus::Busy, busyReason: $reason);
    }

    public static function started(FlowSession $session, int $endedStale): self
    {
        return new self(ContactFlowStartStatus::Started, session: $session, endedStale: $endedStale);
    }

    /**
     * The conversation a {@see ContactFlowStartStatus::Started} outcome started.
     *
     * @throws LogicException for any other outcome, which started none
     */
    public function startedSession(): FlowSession
    {
        if (ContactFlowStartStatus::Started !== $this->status || null === $this->session) {
            throw new LogicException("A {$this->status->value} start outcome has no started session.");
        }

        return $this->session;
    }
}
