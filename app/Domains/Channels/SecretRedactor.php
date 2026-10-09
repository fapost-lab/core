<?php

declare(strict_types=1);

namespace App\Domains\Channels;

/**
 * Removes channel credentials from text before it can reach an exception message, a log or a UI.
 *
 * Provider clients put the secret in the request URL (`/bot<token>/method`), and HTTP stack errors
 * quote that URL. Every client in this domain passes such text through here before throwing.
 */
final class SecretRedactor
{
    public const string MASK = '***';

    /**
     * Replaces every given secret (plain and URL-encoded) and any `bot<token>` URL segment.
     */
    public static function redact(string $text, string ...$secrets): string
    {
        $needles = [];

        foreach ($secrets as $secret) {
            if ('' === $secret) {
                continue;
            }

            $needles[$secret]               = true;
            $needles[rawurlencode($secret)] = true;
            $needles[urlencode($secret)]    = true;
        }

        $needles = array_map('strval', array_keys($needles));

        // Longest first, so a secret that contains another one is masked whole.
        usort($needles, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($needles as $needle) {
            $text = str_replace($needle, self::MASK, $text);
        }

        return (string)preg_replace('#(?<=/bot)[^/\s?\'"]+#', self::MASK, $text);
    }
}
