<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Container-backed registry for published channel integrations.
 *
 * Built-in integrations come from the container tag and keep the {@see ChannelTypeEnum} filter. A connector's channel
 * comes from {@see ConnectorChannels}, which is read on every lookup, not copied at construction: connectors register
 * at boot, and this singleton may be built before. Its type was checked against the connector's manifest and against
 * the built-in types when it was registered, so the enum filter does not apply to it.
 */
final class ChannelRegistry implements ChannelRegistryInterface
{
    /**
     * @var array<string, ChannelIntegrationDefinition>
     */
    private array $definitionsByType = [];

    /**
     * @param  iterable<ChannelIntegrationDefinition>  $definitions
     */
    public function __construct(
        iterable $definitions,
        private readonly Container $container,
        private readonly ?ConnectorChannels $connectorChannels = null,
    ) {
        foreach ($definitions as $definition) {
            if (null === ChannelTypeEnum::tryFrom($definition->channelType)) {
                continue;
            }

            if (isset($this->definitionsByType[$definition->channelType])) {
                throw new InvalidArgumentException(
                    "Duplicate channel integration definition [{$definition->channelType}]."
                );
            }

            $this->definitionsByType[$definition->channelType] = $definition;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function types(): array
    {
        $types = array_keys($this->definitionsByType);

        foreach ($this->connectorChannels?->all() ?? [] as $channel) {
            $types[] = $channel->type;
        }

        return array_values(array_unique($types));
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

        return $this->connectorChannels?->nameOf($channelType) ?? $channelType;
    }

    /**
     * {@inheritDoc}
     */
    public function definition(ChannelTypeEnum|string $channelType): ?ChannelIntegrationDefinition
    {
        $type = $this->normalizeType($channelType);

        return $this->definitionsByType[$type] ?? $this->connectorDefinition($type);
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
    public function adapter(ChannelTypeEnum|string $channelType): ChannelAdapterInterface|ChannelInboundAdapterInterface|null
    {
        $class = $this->definition($channelType)?->adapterClass;

        if (null === $class) {
            return null;
        }

        /** @var ChannelAdapterInterface|ChannelInboundAdapterInterface */
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

    private function connectorDefinition(string $type): ?ChannelIntegrationDefinition
    {
        $channel = $this->connectorChannels?->find($type);

        if (null === $channel) {
            return null;
        }

        return new ChannelIntegrationDefinition(
            channelType: $channel->type,
            senderClass: $channel->sender,
            webhookRegistrarClass: $channel->webhookRegistrar,
            adapterClass: $channel->inbound,
            connectorId: $this->connectorChannels->ownerOf($type),
            capabilities: $channel->capabilities,
            profileClass: $channel->profile,
        );
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
