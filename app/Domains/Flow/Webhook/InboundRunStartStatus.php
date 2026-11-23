<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

/**
 * What {@see InboundRunStarter} did with one accepted inbound call.
 */
enum InboundRunStartStatus: string
{
    /** The call's key was already claimed, and no kept answer was to be handed back; nothing was queued. */
    case Duplicate = 'duplicate';

    /** A run was queued and the caller gets no flow answer: an async trigger, no wait slot, or no answer in time. */
    case Accepted = 'accepted';

    /** The flow's answer: kept for a repeated call of a `sync` trigger, or made while this one waited. */
    case Replied = 'replied';
}
