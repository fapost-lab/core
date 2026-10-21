<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\Settings\ChannelPublicLink;
use Fapost\Foundation\Channel\Settings\ChannelSettingsSchema;

/**
 * The profile of a channel type whose integration declared none: no settings, a secret token of any form and no
 * public link. Core uses it instead of a missing profile, so no screen asks whether one exists.
 */
final readonly class EmptyChannelProfile implements ChannelProfileInterface
{
    public function __construct(private string $type)
    {
    }

    public function channelType(): string
    {
        return $this->type;
    }

    public function settings(): ChannelSettingsSchema
    {
        return new ChannelSettingsSchema();
    }

    public function publicLink(array $config): ?ChannelPublicLink
    {
        return null;
    }
}
