<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use Fapost\Foundation\Media\Enums\MediaKind;

/**
 * How a file is sent to a messenger: as a photo, a video or audio only in the formats messengers play inline,
 * anything else as a document.
 *
 * {@see MediaKind::fromMimeType()} answers what a file is (any `image/*` is an image); this answers how it travels.
 * The two differ for formats the library accepts but a messenger's typed methods refuse: Telegram's sendPhoto takes
 * JPEG, PNG, WebP and GIF, not HEIC, AVIF, BMP or TIFF; sendVideo plays MP4 (MOV and WebM have always gone that way
 * too), not MKV, AVI or 3GP; sendAudio takes MP3 and M4A (OGG and WAV went that way before and keep doing so, as channel refs
 * minted for them hold audio ids), not FLAC or AAC. Only formats the allowlist gained later go as documents. The
 * upload and every later send of the same cached file id use the same answer, since a document's id is no photo's id.
 */
final class DeliveryKind
{
    /** @var array<string, MediaKind> */
    private const array NATIVE = [
        'image/jpeg'      => MediaKind::Image,
        'image/png'       => MediaKind::Image,
        'image/webp'      => MediaKind::Image,
        'image/gif'       => MediaKind::Image,
        'video/mp4'       => MediaKind::Video,
        'video/quicktime' => MediaKind::Video,
        'video/webm'      => MediaKind::Video,
        'audio/mpeg'      => MediaKind::Audio,
        'audio/mp4'       => MediaKind::Audio,
        'audio/x-m4a'     => MediaKind::Audio,
        'audio/ogg'       => MediaKind::Audio,
        'audio/wav'       => MediaKind::Audio,
        'audio/x-wav'     => MediaKind::Audio,
    ];

    public static function of(string $mimeType): MediaKind
    {
        $normalized = mb_strtolower(mb_trim($mimeType));
        $kind       = MediaKind::fromMimeType($normalized);

        if (! in_array($kind, [MediaKind::Image, MediaKind::Video, MediaKind::Audio], true)) {
            return $kind;
        }

        return self::NATIVE[$normalized] ?? MediaKind::Document;
    }
}
