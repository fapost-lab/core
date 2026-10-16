<?php

declare(strict_types=1);

namespace Tests\Fixtures\Connectors\Echo;

use Fapost\Foundation\Media\ChannelMediaLinkPolicyInterface;

/**
 * The fixture channel receives media as a public link.
 */
final class EchoMediaLinkPolicy implements ChannelMediaLinkPolicyInterface
{
    public function channelType(): string
    {
        return 'echo';
    }

    public function linkTtlSeconds(): ?int
    {
        return null;
    }
}
