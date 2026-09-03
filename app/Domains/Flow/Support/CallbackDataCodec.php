<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

/**
 * Encodes/decodes Telegram callback_data as a compact 64-char hex string
 * containing two UUIDs (session_id + button_id) with dashes stripped.
 *
 * Telegram's callback_data limit is 64 bytes; two 16-byte UUIDs as hex fit exactly.
 */
final class CallbackDataCodec
{
    /**
     * Encodes session_id + button_id into a 64-char hex string safe for Telegram callback_data.
     */
    public static function encode(string $sessionId, string $buttonId): string
    {
        return str_replace('-', '', $sessionId) . str_replace('-', '', $buttonId);
    }

    /**
     * Decodes a 64-char hex callback_data into session_id and button_id.
     *
     * @return array{session_id: string, button_id: string}|null  null when the data is not in codec format
     */
    public static function decode(?string $callbackData): ?array
    {
        if (null === $callbackData || 64 !== mb_strlen($callbackData)) {
            return null;
        }

        $s = mb_substr($callbackData, 0, 32);
        $b = mb_substr($callbackData, 32, 32);

        if (! ctype_xdigit($s) || ! ctype_xdigit($b)) {
            return null;
        }

        return [
            'session_id' => sprintf(
                '%s-%s-%s-%s-%s',
                mb_substr($s, 0, 8),
                mb_substr($s, 8, 4),
                mb_substr($s, 12, 4),
                mb_substr($s, 16, 4),
                mb_substr($s, 20, 12)
            ),
            'button_id' => sprintf(
                '%s-%s-%s-%s-%s',
                mb_substr($b, 0, 8),
                mb_substr($b, 8, 4),
                mb_substr($b, 12, 4),
                mb_substr($b, 16, 4),
                mb_substr($b, 20, 12)
            ),
        ];
    }
}
