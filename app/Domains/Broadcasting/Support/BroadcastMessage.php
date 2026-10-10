<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Support;

/**
 * The locale map a broadcast carries as its message (`{lang: text}`): how it is cleaned before it is stored and when it
 * is usable. One place for both screens, so Filament and the console agree on what a stored message is.
 */
final class BroadcastMessage
{
    /**
     * Drops blank and non-string entries so the JSON column stays canonical; an empty map is `null`.
     *
     * @return array<string, string>|null
     */
    public static function clean(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $clean = [];

        foreach ($value as $language => $text) {
            if (is_string($language) && is_string($text) && '' !== mb_trim($text)) {
                $clean[$language] = $text;
            }
        }

        return [] === $clean ? null : $clean;
    }

    /**
     * Whether the base language has text. `SendBroadcastRecipientJob` skips a recipient whose resolved text is blank,
     * so a message without it would burn the whole run without sending anything.
     *
     * @param  array<array-key, mixed>|null  $message
     */
    public static function hasBaseLanguageText(?array $message, string $baseLanguage): bool
    {
        $text = $message[$baseLanguage] ?? null;

        return is_string($text) && '' !== mb_trim($text);
    }
}
