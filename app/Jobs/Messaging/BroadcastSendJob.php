<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use FAPost\Foundation\Messaging\MessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Queue job responsible for low-priority broadcast outbound deliveries.
 */
final class BroadcastSendJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  OutboundMessage  $message  Fully prepared outbound message envelope.
     */
    public function __construct(
        public readonly OutboundMessage $message,
    ) {
        $this->onQueue('messaging.broadcast');
    }

    /**
     * Deliver the message and bubble non-duplicate failures for queue retries.
     */
    public function handle(MessageSenderInterface $sender): void
    {
        $result = $sender->send($this->message);

        if ( ! $result->sent && ! $result->duplicate) {
            throw new RuntimeException($result->error ?? 'Broadcast message delivery failed.');
        }
    }
}
