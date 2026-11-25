<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Whom a flow's broadcast reaches: the contacts with any of some tags, the contacts of a segment, or every contact of
 * the assistant.
 *
 * `tags` and `segment` are the audience addressee kinds of a `send_message` node's config:
 * {@see \App\Domains\Flow\Support\AddresseeResolver::KIND_TAGS} and `KIND_SEGMENT` are these cases' values, so the
 * two cannot drift apart. `all` is not one of them: only a legacy `notify` node in
 * `contacts` mode, published before that mode was retired, names everyone.
 */
enum AudienceKind: string
{
    case Tags    = 'tags';
    case Segment = 'segment';
    case All     = 'all';
}
