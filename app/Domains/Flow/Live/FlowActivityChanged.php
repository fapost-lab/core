<?php

declare(strict_types=1);

namespace App\Domains\Flow\Live;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * "Something changed in this assistant's flow sessions." It carries no data: a screen that hears it reloads what it shows
 * through its own authorized request, so nothing about a contact travels over the websocket.
 *
 * Queued (never sent from inside a flow job: a slow or failing websocket provider must not slow or fail a step) and
 * rescued: a failing push is reported, never thrown. {@see FlowActivityNotifier} dispatches it after the step commits,
 * only while someone watches, at most once per throttle window per assistant.
 */
final class FlowActivityChanged implements ShouldBroadcast, ShouldRescue
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
        return [new PrivateChannel(FlowActivityChannel::name($this->tenantId, $this->assistantId))];
    }

    public function broadcastAs(): string
    {
        return FlowActivityChannel::EVENT;
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
