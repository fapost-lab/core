<?php

declare(strict_types=1);

namespace App\Domains\Staff\Live;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * "Your notifications changed": one arrived, or some were read. It carries no data: the bell reads its count (and its
 * list, when open) again through its own authorized requests, so no notification text travels over the websocket.
 *
 * Queued and rescued like {@see \App\Domains\Conversation\Live\ConversationActivityChanged}: a slow or failing websocket
 * provider never slows or fails whatever sent the notification, and a failing push is reported, never thrown.
 */
final class StaffNotificationsChanged implements ShouldBroadcast, ShouldRescue
{
    public const string QUEUE = 'messaging.system';

    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
    ) {
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(StaffNotificationsChannel::name($this->tenantId, $this->userId))];
    }

    public function broadcastAs(): string
    {
        return StaffNotificationsChannel::EVENT;
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
