<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * Audience selector for a broadcast. `All` reaches every deliverable channel
 * contact of the assistant; `Tags` narrows to contacts carrying any of the
 * configured tags; `Segment` resolves a saved {@see \App\Domains\Contact\Models\ContactSegment}.
 */
enum BroadcastTarget: string
{
    case All     = 'all';
    case Tags    = 'tags';
    case Segment = 'segment';
}
