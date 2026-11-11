<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Events;

use App\Domains\Conversation\Enums\OperatorRequestState;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An operator request left `pending` or `accepted`: an operator accepted it, finished the conversation, the contact
 * cancelled it, or it timed out.
 *
 * Conversation's own listener dims the request notifications; the Flow domain listens to tell the contact, remove the
 * cancel button and move the waiting session on. Conversation does not depend on Flow: this event is the seam.
 * Dispatched only after the change is committed.
 */
final readonly class OperatorRequestStateChanged implements ShouldDispatchAfterCommit
{
    /**
     * @param  string|null  $actorUserId  the operator who accepted or finished, for those two states
     */
    public function __construct(
        public string $tenantId,
        public string $requestId,
        public OperatorRequestState $state,
        public ?string $actorUserId = null,
    ) {
    }
}
