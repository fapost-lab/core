<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
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
     * Always a {@see ChannelInboundAdapterInterface}: it normalizes, and is verified through its ingress spec.
     */
    public function resolve(string $platform): ChannelInboundAdapterInterface
    {
        $adapter = $this->channelRegistry->adapter($platform);

        if (null === $adapter) {
            throw new AdapterNotFoundException("No adapter registered for platform: {$platform}");
        }

        return $adapter;
    }
}
