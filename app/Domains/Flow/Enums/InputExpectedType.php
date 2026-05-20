<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Accepted media kind for an `input` node. `Text` routes through the text-handling
 * branch; everything else flows through the media ingest pipeline. `File` is the
 * permissive variant — any attachment kind is accepted.
 */
enum InputExpectedType: string
{
    case Text     = 'text';
    case File     = 'file';
    case Image    = 'image';
    case Document = 'document';
    case Video    = 'video';
    case Voice    = 'voice';
    case Audio    = 'audio';

    /** @return list<self> */
    public static function mediaTypes(): array
    {
        return [self::File, self::Image, self::Document, self::Video, self::Voice, self::Audio];
    }

    public function isMedia(): bool
    {
        return self::Text !== $this;
    }
}
