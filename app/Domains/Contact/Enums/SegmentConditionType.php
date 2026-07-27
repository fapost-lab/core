<?php

declare(strict_types=1);

namespace App\Domains\Contact\Enums;

/**
 * Contact attribute a segment condition filters on. Scoped to indexed,
 * first-class contact data in V1 — tag membership, content language, and
 * platform. Group / attribute-json conditions arrive with those features.
 */
enum SegmentConditionType: string
{
    case Tag      = 'tag';
    case Language = 'language';
    case Platform = 'platform';
}
