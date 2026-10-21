<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use Fapost\Foundation\Channel\Ingress\IngressSpec;

/**
 * Single source of truth for which platforms can be verified declaratively.
 *
 * Whether an external gateway can handle a platform is a property of its adapter,
 * not a configuration choice: every registered adapter expresses its rules as a spec.
 * Deriving the answer here rather than listing platforms in the environment removes
 * the only way the two could disagree — a configured list naming a platform with no
 * adapter would route live webhooks at a gateway with nothing to verify them against.
 */
final readonly class IngressSpecResolver
{
    public function __construct(
        private ChannelRegistryInterface $channelRegistry,
    ) {
    }

    /**
     * Spec declared by the platform's adapter, or null when no adapter is registered.
     */
    public function specFor(string $platform): ?IngressSpec
    {
        $adapter = $this->channelRegistry->adapter($platform);

        return $adapter?->ingressSpec();
    }

    /**
     * Platforms an external ingress runtime is able to verify.
     *
     * @return list<string>
     */
    public function declarativePlatforms(): array
    {
        $platforms = [];

        foreach ($this->channelRegistry->types() as $type) {
            if (null !== $this->specFor($type)) {
                $platforms[] = $type;
            }
        }

        return $platforms;
    }
}
