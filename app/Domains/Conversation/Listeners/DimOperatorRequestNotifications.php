<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Listeners;

use App\Domains\Conversation\Events\OperatorRequestStateChanged;
use App\Domains\Conversation\Notifications\ConversationOperatorNotifier;

/**
 * Once an operator request stops waiting, the bell notifications about it are marked read and show the outcome, so no
 * bell keeps calling operators into a thread that is taken, cancelled or back with the assistant.
 */
final readonly class DimOperatorRequestNotifications
{
    public function __construct(
        private ConversationOperatorNotifier $notifier,
    ) {
    }

    public function handle(OperatorRequestStateChanged $event): void
    {
        $this->notifier->settled($event->requestId, $event->state, $event->actorUserId);
    }
}
