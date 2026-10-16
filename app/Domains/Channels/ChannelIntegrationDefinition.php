<?php

declare(strict_types=1);

namespace App\Domains\Channels;

/**
 * Declarative description of one channel integration module.
 *
 * `connectorId` is set for a channel a connector brought, and null for a built-in integration.
 */
final readonly class ChannelIntegrationDefinition
{
    public function __construct(
        public string $channelType,
        public ?string $senderClass = null,
        public ?string $webhookRegistrarClass = null,
        public ?string $adapterClass = null,
        public ?string $connectorId = null,
    ) {
    }
}
