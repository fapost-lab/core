<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Live;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * "Something changed in this assistant's conversations": a message was recorded, a thread was taken over, handed back
 * or closed. It carries no data: the inbox screens reload what they show through their own authorized requests, so no
 * message text travels over the websocket.
 *
 * Queued and rescued like {@see \App\Domains\Flow\Live\FlowActivityChanged}: a slow or failing websocket provider never
 * slows or fails the transcript job, and a failing push is reported, never thrown.
 */
final class ConversationActivityChanged implements ShouldBroadcast, ShouldRescue
{
    public const string QUEUE = 'messaging.system';

    public function __construct(
        public readonly string $tenantId,
        public readonly string $assistantId,
    ) {
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(ConversationActivityChannel::name($this->tenantId, $this->assistantId))];
    }

    public function broadcastAs(): string
    {
        return ConversationActivityChannel::EVENT;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }

    public function broadcastQueue(): string
    {
        return self::QUEUE;
    }
}
