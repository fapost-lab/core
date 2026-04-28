<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use FAPost\Foundation\Messaging\MessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Queue job responsible for high-priority transactional outbound deliveries.
 */
final class SendTransactionalMessageJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  OutboundMessage  $message  Fully prepared outbound message envelope.
     */
    public function __construct(
        public readonly OutboundMessage $message,
    ) {
        $this->onQueue('messaging.transactional');
    }

    /**
     * Deliver the message and surface non-duplicate failures to Laravel retries.
     */
    public function handle(MessageSenderInterface $sender): void
    {
        $result = $sender->send($this->message);

        if ($result->duplicate) {
            Log::info('Duplicate transactional message detected and skipped.', [
                'idempotency_key' => $this->message->idempotencyKey,
                'channel_id'      => $this->message->channelId,
                'chat_id'         => $this->message->chatId,
            ]);

            return;
        }

        if ( ! $result->sent) {
            throw new RuntimeException($result->error ?? 'Transactional message delivery failed.');
        }
    }
}
