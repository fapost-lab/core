<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Models\FlowSession;
use Carbon\CarbonImmutable;
use Closure;

/**
 * What one outermost engine call shares with the calls nested in it: since when it runs, how many steps an
 * automation run took in it and which session stopped when that ran out, and the step budget of each session it runs
 * ({@see stepBudget()}).
 *
 * One engine call nests others — a subflow child runs inside its parent's step, a parent resumes inside its child's
 * last step — and all of them share this, so a job never spends a budget once per level. The outermost call opens
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

    /** @var array<string, StepBudget> per session id, its budget for this outermost call */
    private array $stepBudgets = [];

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
     * When the outermost engine call started; now when called outside one.
     */
    public function startedAt(): CarbonImmutable
    {
        return $this->startedAt ?? CarbonImmutable::now();
    }

    /**
     * The step budget of a session for this outermost call: built by {@code $make} on the first call that runs the
     * session, the same object for every nested call that resumes it — a parent resumed inside its subflow step after
     * its child ended goes on from a count that holds that step: a conversation keeps its loop's iteration count and
     * the call's clock, an automation persists an iteration count that never misses the subflow step.
     *
     * @param  Closure(): StepBudget  $make
     */
    public function stepBudget(string $sessionId, Closure $make): StepBudget
    {
        return $this->stepBudgets[$sessionId] ??= $make();
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
        $this->stepBudgets    = [];
    }
}
