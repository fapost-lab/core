<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Whom a `send_message` broadcast reaches: the contacts with any of some tags, or the contacts of a segment.
 *
 * The values are the addressee kinds of the node's config ({@see \App\Domains\Flow\Support\AddresseeResolver}).
 */
enum AudienceKind: string
{
    case Tags    = 'tags';
    case Segment = 'segment';
}
