<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use FAPost\Foundation\Channel\Ingress\IngressSpec;
use FAPost\Foundation\Channel\Ingress\ProvidesIngressSpecInterface;

/**
 * Single source of truth for which platforms can be verified declaratively.
 *
 * Whether an external gateway can handle a platform is a property of its adapter,
 * not a configuration choice: an adapter either expresses its rules as a spec or
 * it does not. Deriving the answer here rather than listing platforms in the
 * environment removes the only way the two could disagree — a configured list
 * naming a platform that publishes no spec would route live webhooks at a gateway
 * with nothing to verify them against.
 */
final readonly class IngressSpecResolver
{
    public function __construct(
        private ChannelRegistryInterface $channelRegistry,
    ) {
    }

    /**
     * Spec declared by the platform's adapter, or null when the adapter either
     * is not registered or keeps its verification in code.
     */
    public function specFor(string $platform): ?IngressSpec
    {
        $adapter = $this->channelRegistry->adapter($platform);

        return $adapter instanceof ProvidesIngressSpecInterface
            ? $adapter->ingressSpec()
            : null;
    }

    /**
     * Platforms an external ingress runtime is able to verify.
     *
     * @return list<string>
     */
    public function declarativePlatforms(): array
    {
        $platforms = [];

        foreach (ChannelTypeEnum::cases() as $type) {
            if (null !== $this->specFor($type->value)) {
                $platforms[] = $type->value;
            }
        }

        return $platforms;
    }
}
