<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Typing;

/**
 * Per-request handoff point for the typing indicator started by the routing
 * pipeline. {@see \App\Domains\Flow\Routing\MessageRouter} sets the active
 * {@see TypingSession} immediately after acquiring it; the
 * {@see \App\Domains\Flow\Services\FlowEngine} reads it before every
 * handler.execute() and issues a {@see TypingSession::refresh()} so the
 * indicator survives multi-node execution beyond the provider's TTL
 * (Telegram drops the typing chat action after ~5 seconds).
 *
 * Bound as {@code scoped} in the container — one instance per request /
 * queue job, never leaks across worker invocations.
 *
 * Octane note: the registry holds plain object refs; lifecycle is bounded
 * by the request scope and {@see clear()} is idempotent. The router calls
 * {@code clear()} in a finally block, the engine never mutates the slot.
 */
final class TypingHeartbeatRegistry
{
    private ?TypingSession $session = null;

    public function set(TypingSession $session): void
    {
        $this->session = $session;
    }

    public function current(): ?TypingSession
    {
        return $this->session;
    }

    public function clear(): void
    {
        $this->session = null;
    }
}
