<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use App\Domains\Flow\Enums\RerunRefusal;

/**
 * What asking to re-run a run from the start answers: refused with a reason, or possible on a version of the flow as the
 * next attempt of its re-runs — and, once queued, the fire key the new run starts under.
 */
final readonly class RerunOutcome
{
    private function __construct(
        public ?RerunRefusal $refusal,
        public ?int $targetVersion,
        public ?int $attempt,
        public ?string $fireKey,
    ) {
    }

    public static function refused(RerunRefusal $refusal): self
    {
        return new self($refusal, null, null, null);
    }

    /**
     * @param  int  $attempt  the re-run a request would start: the highest attempt among the source's re-runs that have a run, plus one
     */
    public static function possible(int $targetVersion, int $attempt): self
    {
        return new self(null, $targetVersion, $attempt, null);
    }

    public static function queued(int $targetVersion, int $attempt, string $fireKey): self
    {
        return new self(null, $targetVersion, $attempt, $fireKey);
    }

    public function isRefused(): bool
    {
        return null !== $this->refusal;
    }
}
