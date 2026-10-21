<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use Fapost\Foundation\Media\ChannelMediaLimitsInterface;
use Fapost\Foundation\Media\Enums\MediaKind;

/**
 * Compares an uploaded file's size against the provider limits each channel integration declares and surfaces
 * the resulting list of warnings to the picker.
 *
 * Warnings are advisory: they never block the upload. The picker uses them to flag
 * "this file will not deliver via Telegram" before the author wires the file into a
 * send_message node.
 */
final readonly class ChannelLimitInspector
{
    /**
     * @param  iterable<ChannelMediaLimitsInterface>  $limits  What each channel type's integration declared.
     */
    public function __construct(
        private iterable $limits = [],
    ) {
    }

    /**
     * @return list<array{channel_type: string, kind: string, limit_bytes: int, actual_bytes: int}>
     */
    public function evaluate(MediaKind $kind, int $sizeBytes): array
    {
        $warnings = [];

        foreach ($this->limits as $channelLimits) {
            $limit = $channelLimits->maxBytes($kind);

            if (null === $limit || $sizeBytes <= $limit) {
                continue;
            }

            $warnings[] = [
                'channel_type' => $channelLimits->channelType(),
                'kind'         => $kind->value,
                'limit_bytes'  => $limit,
                'actual_bytes' => $sizeBytes,
            ];
        }

        return $warnings;
    }
}
