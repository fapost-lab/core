<?php

declare(strict_types=1);

namespace App\Domains\Conversation\DTO;

use App\Domains\Conversation\Enums\OperatorRequestPlacementOutcome;

/**
 * The result of placing an operator request: what happened and, when there is a request, the request and the channel
 * type of its thread (the node decides by it whether a cancel button can be shown).
 */
final readonly class OperatorRequestPlacement
{
    public function __construct(
        public OperatorRequestPlacementOutcome $outcome,
        public ?OperatorRequest $request = null,
        public ?string $channelType = null,
    ) {
    }

    public static function refused(OperatorRequestPlacementOutcome $outcome): self
    {
        return new self($outcome);
    }
}
