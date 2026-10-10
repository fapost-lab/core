<?php

declare(strict_types=1);

namespace App\Domains\Contact\Enums;

/**
 * How many values a segment condition reads: none (`exists`), exactly one (the resolver only looks at the first value
 * of a tag, an attribute comparison or `eq`), or a list.
 */
enum SegmentValueArity: string
{
    case None = 'none';
    case One  = 'one';
    case Many = 'many';
}
