<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Contracts\ChannelAdapterInterface;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;

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
     */
    public function resolve(PlatformEnum $platform): ChannelAdapterInterface
    {
        $adapter = $this->channelRegistry->adapter($platform->value);

        if (null === $adapter) {
            throw new AdapterNotFoundException("No adapter registered for platform: {$platform->value}");
        }

        return $adapter;
    }
}
