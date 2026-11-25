<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use App\Domains\Flow\Enums\AudienceKind;

/**
 * A flow node's request to broadcast one message: a `send_message` node's to tags or a segment, or a legacy `notify`
 * node's in `contacts` mode to tags or everyone.
 *
 * The message is rendered by the flow at the node and is frozen as it is: `{lang: text}`, or a plain string for the
 * base language. The assistant is never the run's: `send_message` names the flow's, a legacy `notify` node its own.
 */
final readonly class AudienceBroadcastRequest
{
    /**
     * @param  list<string>                   $tags        the tags of a tags audience ("any of")
     * @param  array<string, string>|string   $message
     * @param  string                         $originKey   identifies the node execution: the same key never starts two
     * @param  int                            $chainDepth  the depth the broadcast's completion event is published with
     */
    public function __construct(
        public string $tenantId,
        public string $assistantId,
        public string $name,
        public AudienceKind $audience,
        public array $tags,
        public ?string $segmentId,
        public array|string $message,
        public string $flowId,
        public string $sessionId,
        public string $nodeId,
        public string $originKey,
        public int $chainDepth,
    ) {
    }
}
