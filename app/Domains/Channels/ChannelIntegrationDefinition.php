<?php

declare(strict_types=1);

namespace App\Domains\Channels;

/**
 * Declarative description of one channel integration module.
 */
final readonly class ChannelIntegrationDefinition
{
    public function __construct(
        public string $channelType,
        public ?string $senderClass = null,
        public ?string $webhookRegistrarClass = null,
        public ?string $adapterClass = null,
    ) {
    }
}
