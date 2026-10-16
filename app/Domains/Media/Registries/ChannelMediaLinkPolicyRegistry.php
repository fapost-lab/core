<?php

declare(strict_types=1);

namespace App\Domains\Media\Registries;

use Fapost\Foundation\Media\ChannelMediaLinkPolicyInterface;

/**
 * Channel types that receive media as a public link, by channel type.
 *
 * Populated at boot from the container tag `media.channel.link`, like {@see ChannelMediaUploaderRegistry}; the
 * dispatcher asks it only for a type that has no uploader.
 */
final class ChannelMediaLinkPolicyRegistry
{
    /** @var array<string, ChannelMediaLinkPolicyInterface> */
    private array $policies = [];

    /**
     * @param  iterable<ChannelMediaLinkPolicyInterface>  $policies
     */
    public function __construct(iterable $policies = [])
    {
        foreach ($policies as $policy) {
            $this->register($policy);
        }
    }

    public function register(ChannelMediaLinkPolicyInterface $policy): void
    {
        $this->policies[$policy->channelType()] = $policy;
    }

    public function forChannelType(string $channelType): ?ChannelMediaLinkPolicyInterface
    {
        return $this->policies[$channelType] ?? null;
    }
}
