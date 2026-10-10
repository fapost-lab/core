<?php

declare(strict_types=1);

namespace App\Domains\Channels\Enums;

/**
 * What the provider answered the last time the channel's webhook was registered. Null on the channel means unknown:
 * it was never synchronized, or the type has no provider webhook.
 */
enum ChannelWebhookStatus: string
{
    case Registered = 'registered';
    case Failed     = 'failed';
}
