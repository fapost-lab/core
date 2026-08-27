<?php

declare(strict_types=1);

namespace App\Domains\Contact\Enums;

/**
 * Contact attribute a segment condition filters on: tag membership, content
 * language, platform, a flow-collected value in the `attributes` json
 * (dot-path key), or {@see \App\Domains\Contact\Models\ContactGroup} membership.
 */
enum SegmentConditionType: string
{
    case Tag       = 'tag';
    case Language  = 'language';
    case Platform  = 'platform';
    case Attribute = 'attribute';
    case Group     = 'group';
}
