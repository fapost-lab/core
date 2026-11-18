<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Models\FlowSession;
use Carbon\CarbonImmutable;

/**
 * The budget of one engine call of an automation run: how many steps it ran and since when, and which session
 * stopped when the budget ran out.
 *
 * One engine call nests others — a subflow child runs inside its parent's step, a parent resumes inside its child's
 * last step — and all of them share this budget, so a job never spends it once per level. The outermost call opens
 * the segment ({@see enter()} at depth 0) and closes it ({@see leave()} back at depth 0); nothing survives that, so
 * a console command or a sync queue running several engine calls in one scope starts each with a fresh segment.
 *
 * Bound as {@code scoped}: one per request or job. Worker safety: plain values only, reset on every outermost exit.
 */
final class ExecutionSegment
{
    private int $depth = 0;

    private ?CarbonImmutable $startedAt = null;

    private int $steps = 0;

    private ?FlowSession $stoppedSession = null;

    /**
     * Enter an engine call; the outermost one starts the segment's clock.
     */
    public function enter(): void
    {
        if (0 === $this->depth) {
            $this->reset();
            $this->startedAt = CarbonImmutable::now();
        }

        ++$this->depth;
    }

    /**
     * Leave an engine call. Back at the outermost level the segment closes and returns the session that stopped
     * for a continuation, if one did; a nested call returns null.
     */
    public function leave(): ?FlowSession
    {
        $this->depth = max(0, $this->depth - 1);

        if (0 !== $this->depth) {
            return null;
        }

        $stopped = $this->stoppedSession;
        $this->reset();

        return $stopped;
    }

    public function countStep(): void
    {
        ++$this->steps;
    }

    /**
     * True once the segment ran at least one step and reached either budget — so every segment makes progress.
     */
    public function exhausted(int $maxSteps, int $maxSeconds): bool
    {
        if (0 === $this->steps || null === $this->startedAt) {
            return false;
        }

        return $this->steps >= $maxSteps
            || $this->startedAt->diffInSeconds(CarbonImmutable::now()) >= $maxSeconds;
    }

    /**
     * Record the session that stopped at its saved position, to be continued in a new job.
     */
    public function stopAt(FlowSession $session): void
    {
        $this->stoppedSession = $session;
    }

    private function reset(): void
    {
        $this->startedAt      = null;
        $this->steps          = 0;
        $this->stoppedSession = null;
    }
}
