<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\DTOs\AudienceBroadcastRequest;
use App\Domains\Flow\DTOs\AudienceBroadcastResult;

/**
 * Flow's port to Broadcasting: a `send_message` node whose addressee is tags or a segment, and a legacy `notify` node in
 * `contacts` mode, start a broadcast through it and go on. Implemented in `app/Infrastructure/Flow`, so the Flow runtime does not depend on Broadcasting.
 *
 * {@see start()} never throws for an expected refusal: it answers with an outcome. A request whose `originKey` started a
 * broadcast before gets that broadcast back, so a retried step starts nothing twice; a node with a broadcast still
 * running starts none.
 */
interface AudienceBroadcasterInterface
{
    public function start(AudienceBroadcastRequest $request): AudienceBroadcastResult;

    /**
     * Whether the segment is one of the current tenant's, for validating a node's audience before it is published.
     * False outside a tenant.
     */
    public function segmentExists(string $segmentId): bool;
}
