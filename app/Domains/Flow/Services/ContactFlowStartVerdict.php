<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Models\FlowSession;

/**
 * What {@see ContactFlowStartCollision} decided: busy (with its reason), or free, with the stale live conversations a
 * start ends before it begins its own.
 */
final readonly class ContactFlowStartVerdict
{
    /**
     * @param  list<FlowSession>  $stale
     */
    private function __construct(
        public ?string $busyReason,
        public array $stale,
    ) {
    }

    public static function busy(string $reason): self
    {
        return new self($reason, []);
    }

    /**
     * @param  list<FlowSession>  $stale
     */
    public static function free(array $stale): self
    {
        return new self(null, $stale);
    }

    public function isBusy(): bool
    {
        return null !== $this->busyReason;
    }
}
