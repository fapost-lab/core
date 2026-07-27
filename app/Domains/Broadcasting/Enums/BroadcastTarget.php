<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * Audience selector for a broadcast. `All` reaches every deliverable channel
 * contact of the assistant; `Tags` narrows to contacts carrying any of the
 * configured tags. Groups / segments arrive with the segmentation feature.
 */
enum BroadcastTarget: string
{
    case All  = 'all';
    case Tags = 'tags';
}
