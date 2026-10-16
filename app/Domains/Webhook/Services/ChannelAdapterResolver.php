<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;

/**
 * Resolves inbound webhook adapters through the shared channel registry.
 */
final readonly class ChannelAdapterResolver
{
    public function __construct(
        private ChannelRegistryInterface $channelRegistry,
    ) {
    }

    /**
     * Resolve the adapter for the given external platform or fail explicitly.
     *
     * A built-in adapter or a connector's {@see ChannelInboundAdapterInterface}; both normalize, and the second one is
     * always verified through its ingress spec.
     */
    public function resolve(string $platform): ChannelAdapterInterface|ChannelInboundAdapterInterface
    {
        $adapter = $this->channelRegistry->adapter($platform);

        if (null === $adapter) {
            throw new AdapterNotFoundException("No adapter registered for platform: {$platform}");
        }

        return $adapter;
    }
}
