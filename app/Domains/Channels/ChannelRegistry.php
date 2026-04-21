<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use FAPost\Foundation\Channel\WebhookRegistrarInterface;
use FAPost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Container-backed registry for published channel integrations.
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
    ) {
        foreach ($definitions as $definition) {
            if (null === ChannelTypeEnum::tryFrom($definition->channelType)) {
                continue;
            }

            if (isset($this->definitionsByType[$definition->channelType])) {
                throw new InvalidArgumentException("Duplicate channel integration definition [{$definition->channelType}].");
            }

            $this->definitionsByType[$definition->channelType] = $definition;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function definition(ChannelTypeEnum|string $channelType): ?ChannelIntegrationDefinition
    {
        return $this->definitionsByType[$this->normalizeType($channelType)] ?? null;
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
    public function adapter(ChannelTypeEnum|string $channelType): ?ChannelAdapterInterface
    {
        $class = $this->definition($channelType)?->adapterClass;

        if (null === $class) {
            return null;
        }

        /** @var ChannelAdapterInterface */
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
