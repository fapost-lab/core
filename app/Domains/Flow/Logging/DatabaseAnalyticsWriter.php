<?php

declare(strict_types=1);

namespace App\Domains\Flow\Logging;

use Fapost\Foundation\Analytics\Contracts\AnalyticsWriterInterface;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use JsonException;

final readonly class DatabaseAnalyticsWriter implements AnalyticsWriterInterface
{
    /**
     * @param  ConnectionResolverInterface  $db  asked for its connection at the moment it writes, never kept: a tenant
     *                                         switch moves the default connection the models write through, and a
     *                                         transaction on a connection taken when this is built — before the
     *                                         switch, in a queue job — would not cover them
     */
    public function __construct(
        private ConnectionResolverInterface $db,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function record(AnalyticsEvent $event): void
    {
        $this->db->connection()->table('analytics_events')->insert([
            'id'          => Str::ulid()->toRfc4122(),
            'tenant_id'   => $event->tenantId,
            'event_type'  => $event->eventType->value,
            'payload'     => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:sP'),
        ]);
    }
}
