<?php

declare(strict_types=1);

namespace App\Domains\Media\Preview;

use App\Domains\Media\Models\MediaFile;

/**
 * Open extension point: maps file extensions to their preview descriptor.
 *
 * Plugins and Solutions can call {@see register()} from their service provider boot
 * to add new preview types (e.g. CAD viewer, 3D model). Resolution falls back to
 * `null` so callers can render the generic icon fallback.
 */
final class MediaPreviewRegistry
{
    /** @var array<string, MediaPreviewType> */
    private array $byExtension = [];

    public function register(MediaPreviewType $type): void
    {
        foreach ($type->extensions as $ext) {
            $this->byExtension[mb_strtolower($ext)] = $type;
        }
    }

    public function resolve(MediaFile $file): ?MediaPreviewType
    {
        $extension = mb_strtolower((string)pathinfo($file->name, PATHINFO_EXTENSION));

        if ('' === $extension) {
            return null;
        }

        return $this->byExtension[$extension] ?? null;
    }
}
