<?php

declare(strict_types=1);

namespace App\Domains\Contact\Enums;

/**
 * Comparison a segment condition applies to its contact attribute. Which operators a condition type accepts, and how
 * many values each takes, is decided by {@see SegmentConditionType}.
 */
enum SegmentOperator: string
{
    case Has    = 'has';
    case NotHas = 'not_has';
    case In     = 'in';
    case NotIn  = 'not_in';
    case Eq     = 'eq';
    case Ne     = 'ne';
    case Exists = 'exists';
}
