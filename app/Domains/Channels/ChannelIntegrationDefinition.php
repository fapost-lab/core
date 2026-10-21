<?php

declare(strict_types=1);

namespace App\Domains\Channels;

/**
 * Declarative description of one channel integration module.
 *
 * `connectorId` is set for a channel a connector brought, and null for a built-in integration. `profileClass` names the
 * {@see \Fapost\Foundation\Channel\Settings\ChannelProfileInterface} that declares the channel's settings and public link;
 * without one the channel has none.
 */
final readonly class ChannelIntegrationDefinition
{
    public function __construct(
        public string $channelType,
        public ?string $senderClass = null,
        public ?string $webhookRegistrarClass = null,
        public ?string $adapterClass = null,
        public ?string $connectorId = null,
        public ?string $profileClass = null,
    ) {
    }
}
