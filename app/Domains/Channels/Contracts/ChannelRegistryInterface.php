<?php

declare(strict_types=1);

namespace App\Domains\Channels\Contracts;

use App\Domains\Channels\ChannelIntegrationDefinition;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use FAPost\Foundation\Channel\WebhookRegistrarInterface;
use FAPost\Foundation\Messaging\ProviderSenderInterface;

/**
 * Registry of enabled channel integrations and their runtime services.
 */
interface ChannelRegistryInterface
{
    /**
     * Return the declarative integration definition for the given channel type.
     */
    public function definition(ChannelTypeEnum|string $channelType): ?ChannelIntegrationDefinition;

    /**
     * Resolve the outbound sender for the given channel type.
     */
    public function sender(ChannelTypeEnum|string $channelType): ?ProviderSenderInterface;

    /**
     * Resolve the webhook registrar for the given channel type.
     */
    public function webhookRegistrar(ChannelTypeEnum|string $channelType): ?WebhookRegistrarInterface;

    /**
     * Resolve the inbound adapter for the given channel type.
     */
    public function adapter(ChannelTypeEnum|string $channelType): ?ChannelAdapterInterface;
}
