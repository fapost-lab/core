<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\DTOs;

use App\Domains\Broadcasting\Enums\BroadcastTarget;

/**
 * A flow node's request to send one message to an audience, as {@see \App\Domains\Broadcasting\Services\AutomationBroadcasts}
 * takes it. The message is already rendered by the flow and is frozen as it is; a plain string is the base language's
 * text.
 *
 * @phpstan-type Message array<string, string>|string
 */
final readonly class AutomationBroadcastRequest
{
    /**
     * @param  list<string>               $tags        the tags of a `Tags` target ("any of")
     * @param  array<string, string>|string  $message  `{lang: text}`, or the base language's text
     * @param  string                     $originKey   identifies the node execution; the same key never starts two
     * @param  int                        $chainDepth  the depth the broadcast's completion event is published with
     */
    public function __construct(
        public string $tenantId,
        public string $assistantId,
        public string $name,
        public BroadcastTarget $target,
        public array $tags,
        public ?string $segmentId,
        public array|string $message,
        public string $flowId,
        public string $flowSessionId,
        public string $flowNodeId,
        public string $originKey,
        public int $chainDepth,
    ) {
    }
}
