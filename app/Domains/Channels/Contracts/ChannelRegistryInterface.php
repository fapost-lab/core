<?php

declare(strict_types=1);

namespace App\Domains\Channels\Contracts;

use App\Domains\Channels\ChannelIntegrationDefinition;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Fapost\Foundation\Messaging\ProviderSenderInterface;

/**
 * Registry of enabled channel integrations and their runtime services.
 */
interface ChannelRegistryInterface
{
    /**
     * Every channel type that has an integration: the built-in ones, then the connectors'.
     *
     * @return list<string>
     */
    public function types(): array;

    /**
     * Whether an integration serves the type. A channel row may hold a type that none serves any more.
     */
    public function has(string $channelType): bool;

    /**
     * Name of the type for people: a built-in type's translation, or the name of the connector that owns it.
     */
    public function label(string $channelType): string;

    /**
     * Return the declarative integration definition for the given channel type.
     */
    public function definition(ChannelTypeEnum|string $channelType): ?ChannelIntegrationDefinition;

    /**
     * What the channel type can do beyond text and media: a built-in integration's declaration, else the
     * connector's. A type nothing serves has none.
     */
    public function capabilities(string $channelType): ChannelCapabilities;

    /**
     * Resolve the outbound sender for the given channel type.
     */
    public function sender(ChannelTypeEnum|string $channelType): ?ProviderSenderInterface;

    /**
     * Resolve the webhook registrar for the given channel type.
     */
    public function webhookRegistrar(ChannelTypeEnum|string $channelType): ?WebhookRegistrarInterface;

    /**
     * Resolve the inbound adapter for the given channel type: a built-in one, or a connector's declarative one.
     */
    public function adapter(ChannelTypeEnum|string $channelType): ChannelAdapterInterface|ChannelInboundAdapterInterface|null;
}
