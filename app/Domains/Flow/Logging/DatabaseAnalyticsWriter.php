<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging;

use Fapost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use JsonException;

final readonly class DatabaseAnalyticsWriter implements AnalyticsWriterInterface
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function record(AnalyticsEvent $event): void
    {
        $this->connection->table('analytics_events')->insert([
            'id'          => Str::ulid()->toRfc4122(),
            'tenant_id'   => $event->tenantId,
            'event_type'  => $event->eventType->value,
            'payload'     => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:sP'),
        ]);
    }
}
