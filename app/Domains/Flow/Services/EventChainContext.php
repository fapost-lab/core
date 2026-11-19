<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

/**
 * The event chain depth of the session the engine is running right now, so an event raised while it runs — by an
 * `emit_event` node, or by a Core fact a node causes (a tag set, a contact created) — is one level deeper.
 *
 * `FlowEngine::executeLoop()` pushes the depth of the session it runs ({@see enter()}) and pops it in a `finally`
 * ({@see leave()}); nested engine calls (a subflow child inside its parent's step, a parent resumed by its child) push
 * their own. Outside any engine call the stack is empty and {@see current()} is 0, so an event raised by an admin
 * action, a webhook or a broadcast job starts a chain at depth 1. The depth that must survive a job boundary lives on
 * the session row (`flow_sessions.event_chain_depth`), never here.
 *
 * Bound as {@code scoped}: one per request or job. Worker safety: plain integers only, emptied by every outermost exit.
 */
final class EventChainContext
{
    /** @var list<int> */
    private array $depths = [];

    public function enter(int $depth): void
    {
        $this->depths[] = max(0, $depth);
    }

    public function leave(): void
    {
        array_pop($this->depths);
    }

    /**
     * The depth of the innermost running session, or 0 outside any engine call.
     */
    public function current(): int
    {
        return [] === $this->depths ? 0 : $this->depths[array_key_last($this->depths)];
    }
}
