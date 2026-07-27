<?php

declare(strict_types=1);

namespace App\Domains\Contact\Enums;

/**
 * Contact attribute a segment condition filters on: tag membership, content
 * language, platform, or a flow-collected value in the `attributes` json
 * (dot-path key). Group conditions arrive with the contact-groups feature.
 */
enum SegmentConditionType: string
{
    case Tag       = 'tag';
    case Language  = 'language';
    case Platform  = 'platform';
    case Attribute = 'attribute';
}
