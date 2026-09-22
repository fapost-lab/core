<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest\Support;

/**
 * Outcome of {@see LoadTestSessionVerifier::verify()}.
 *
 * `dropped` is not a failure by itself — a contact whose lock could not be
 * acquired in time is a `RoutingOutcome::dropped('lock_timeout')`, which
 * {@code MessageRouter} already reports to the user via {@code DropPolicy}.
 * `leaks` and `corrupted` are: a leak means one contact's session ended up
 * holding another contact's (or tenant's) data.
 */
final readonly class LoadTestVerificationReport
{
    /**
     * @param  list<array<string, mixed>>  $leaks
     * @param  list<array<string, mixed>>  $corrupted
     */
    public function __construct(
        public int $total,
        public int $ok,
        public int $dropped,
        public array $leaks,
        public array $corrupted,
    ) {
    }

    public function droppedRatio(): float
    {
        return $this->total > 0 ? $this->dropped / $this->total : 0.0;
    }

    public function hasLeaks(): bool
    {
        return [] !== $this->leaks;
    }

    public function hasCorruption(): bool
    {
        return [] !== $this->corrupted;
    }
}
