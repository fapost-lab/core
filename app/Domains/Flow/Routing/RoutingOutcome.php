<?php

declare(strict_types=1);

namespace App\Domains\Flow\Routing;

/**
 * Immutable summary of how the {@see MessageRouter} processed a message.
 * Returned to the caller (typically a webhook ingress job) so transport-
 * specific retry / observability decisions stay in the caller, not in the
 * router.
 */
final readonly class RoutingOutcome
{
    private function __construct(
        public string $kind,
        public ?string $reason = null,
        public ?SessionRoutingDecision $decision = null,
        public ?string $command = null,
    ) {
    }

    public static function commandHandled(string $command): self
    {
        return new self(kind: 'command', command: $command);
    }

    public static function executed(SessionRoutingDecision $decision): self
    {
        return new self(kind: 'executed', decision: $decision);
    }

    public static function dropped(string $reason): self
    {
        return new self(kind: 'dropped', reason: $reason);
    }

    public function wasDropped(): bool
    {
        return 'dropped' === $this->kind;
    }
}
