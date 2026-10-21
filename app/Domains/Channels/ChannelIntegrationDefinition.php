<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use Fapost\Foundation\Messaging\ChannelCapabilities;

/**
 * Declarative description of one channel integration module.
 *
 * `connectorId` is set for a channel a connector brought, and null for a built-in integration. `capabilities` is
 * what the channel can do beyond text and media; nothing declared means text and media only. `profileClass` names the
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
        public ChannelCapabilities $capabilities = new ChannelCapabilities(),
        public ?string $profileClass = null,
    ) {
    }
}
