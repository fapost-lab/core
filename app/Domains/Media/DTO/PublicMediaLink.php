<?php

declare(strict_types=1);

namespace App\Domains\Media\DTO;

use Carbon\CarbonImmutable;
use Fapost\Foundation\Media\Enums\MediaKind;

/**
 * A freshly issued public link to a media file, for a channel that sends media by URL.
 *
 * The URL is a bearer credential until `expiresAt`: it is handed to the channel driver in the outgoing payload and
 * must not be logged, stored or put into job arguments.
 */
final readonly class PublicMediaLink
{
    public function __construct(
        public string $url,
        public CarbonImmutable $expiresAt,
        public string $mimeType,
        public int $size,
        public string $fileName,
        public MediaKind $kind,
    ) {
    }
}
