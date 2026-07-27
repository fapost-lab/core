<?php

declare(strict_types=1);

namespace App\Domains\Contact\Enums;

/**
 * Boolean combinator for a segment's conditions: All = every condition must
 * hold (AND), Any = at least one holds (OR).
 */
enum SegmentMatch: string
{
    case All = 'all';
    case Any = 'any';
}
