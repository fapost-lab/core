<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Container\Container;

/**
 * Container-backed registry for published channel integrations.
 *
 * Every channel comes from {@see ConnectorChannels}, which is read on every lookup, not copied at construction: the
 * built-in modules and the connectors register at boot, and this singleton may be built before. A built-in channel
 * has no owning connector, so its definition carries a null `connectorId`.
 */
final class ChannelRegistry implements ChannelRegistryInterface
{
    public function __construct(
        private readonly Container $container,
        private readonly ConnectorChannels $channels,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function types(): array
    {
        return array_map(static fn (ConnectorChannel $channel): string => $channel->type, $this->channels->all());
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $channelType): bool
    {
        return null !== $this->definition($channelType);
    }

    /**
     * {@inheritDoc}
     */
    public function label(string $channelType): string
    {
        $builtIn = ChannelTypeEnum::tryFrom($channelType);

        if (null !== $builtIn) {
            return __($builtIn->labelKey());
        }

        return $this->channels->nameOf($channelType) ?? $channelType;
    }

    /**
     * {@inheritDoc}
     */
    public function definition(ChannelTypeEnum|string $channelType): ?ChannelIntegrationDefinition
    {
        $type    = $this->normalizeType($channelType);
        $channel = $this->channels->find($type);

        if (null === $channel) {
            return null;
        }

        return new ChannelIntegrationDefinition(
            channelType: $channel->type,
            senderClass: $channel->sender,
            webhookRegistrarClass: $channel->webhookRegistrar,
            adapterClass: $channel->inbound,
            connectorId: $this->channels->ownerOf($type),
            capabilities: $channel->capabilities,
            profileClass: $channel->profile,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function capabilities(string $channelType): ChannelCapabilities
    {
        return $this->definition($channelType)?->capabilities ?? new ChannelCapabilities();
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function sender(ChannelTypeEnum|string $channelType): ?ProviderSenderInterface
    {
        $class = $this->definition($channelType)?->senderClass;

        if (null === $class) {
            return null;
        }

        /** @var ProviderSenderInterface */
        return $this->container->make($class);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function webhookRegistrar(ChannelTypeEnum|string $channelType): ?WebhookRegistrarInterface
    {
        $class = $this->definition($channelType)?->webhookRegistrarClass;

        if (null === $class) {
            return null;
        }

        /** @var WebhookRegistrarInterface */
        return $this->container->make($class);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function adapter(ChannelTypeEnum|string $channelType): ?ChannelInboundAdapterInterface
    {
        $class = $this->definition($channelType)?->adapterClass;

        if (null === $class) {
            return null;
        }

        /** @var ChannelInboundAdapterInterface */
        return $this->container->make($class);
    }

    /**
     * {@inheritDoc}
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function profile(string $channelType): ChannelProfileInterface
    {
        $class = $this->definition($channelType)?->profileClass;

        if (null === $class) {
            return new EmptyChannelProfile($channelType);
        }

        /** @var ChannelProfileInterface */
        return $this->container->make($class);
    }

    /**
     * Normalize either an enum case or raw key into the canonical channel type string.
     */
    private function normalizeType(ChannelTypeEnum|string $channelType): string
    {
        return $channelType instanceof ChannelTypeEnum
            ? $channelType->value
            : $channelType;
    }
}
