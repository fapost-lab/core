<?php

declare(strict_types=1);

namespace App\Domains\Media\Enums;

/**
 * Origin of a media file inside the platform.
 *
 * Internal taxonomy — not exposed across the extension boundary, so it lives in Core
 * rather than Foundation.
 */
enum MediaSource: string
{
    case Upload       = 'upload';
    case InputNode    = 'input_node';
    case Api          = 'api';
    case Conversation = 'conversation';
}
