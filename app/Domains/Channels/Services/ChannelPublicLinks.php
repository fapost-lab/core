<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use Fapost\Foundation\Channel\Settings\ChannelPublicLink;

/**
 * How a person reaches a channel's bot or account: asked of the profile the channel's integration declared, from the
 * channel's stored `config`. A type nothing serves, or one whose integration has no link, has none.
 */
final readonly class ChannelPublicLinks
{
    public function __construct(private ChannelRegistryInterface $registry)
    {
    }

    public function for(Channel $channel): ?ChannelPublicLink
    {
        if (! $this->registry->has($channel->type)) {
            return null;
        }

        return $this->registry->profile($channel->type)->publicLink(is_array($channel->config) ? $channel->config : []);
    }
}
