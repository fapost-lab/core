<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use Fapost\Foundation\Media\Enums\MediaKind;

/**
 * Compares an uploaded file's size against per-channel provider limits and surfaces
 * the resulting list of warnings to the picker.
 *
 * Warnings are advisory: they never block the upload. The picker uses them to flag
 * "this file will not deliver via Telegram" before the author wires the file into a
 * send_message node.
 */
final readonly class ChannelLimitInspector
{
    /**
     * @param  array<string, array<string, int>>  $limits  Keyed channel_type → kind → max bytes.
     */
    public function __construct(
        private array $limits,
    ) {
    }

    /**
     * @return list<array{channel_type: string, kind: string, limit_bytes: int, actual_bytes: int}>
     */
    public function evaluate(MediaKind $kind, int $sizeBytes): array
    {
        $warnings = [];

        foreach ($this->limits as $channelType => $perKind) {
            $limit = $perKind[$kind->value] ?? null;

            if (! is_int($limit) || $sizeBytes <= $limit) {
                continue;
            }

            $warnings[] = [
                'channel_type' => $channelType,
                'kind'         => $kind->value,
                'limit_bytes'  => $limit,
                'actual_bytes' => $sizeBytes,
            ];
        }

        return $warnings;
    }
}
