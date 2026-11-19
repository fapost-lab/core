<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

/**
 * Removes known secret literals from a value of any shape before it is written anywhere a person can read it.
 *
 * Walks arrays recursively and replaces every occurrence of a secret — plain, `rawurlencode()`d and `urlencode()`d — in
 * every string, keys included. A secret shorter than {@see self::MIN_SECRET_LENGTH} characters is ignored: masking
 * `ab` everywhere would destroy the text and hide nothing worth hiding. Other values are returned unchanged.
 */
final class SecretRedactor
{
    public const string MASK = '***';

    public const int MIN_SECRET_LENGTH = 4;

    /**
     * @param  list<string>  $secrets
     */
    public static function redact(mixed $value, array $secrets): mixed
    {
        $needles = self::needles($secrets);

        if ([] === $needles) {
            return $value;
        }

        return self::walk($value, $needles);
    }

    /**
     * @param  list<string>  $needles
     */
    private static function walk(mixed $value, array $needles): mixed
    {
        if (is_string($value)) {
            return str_replace($needles, self::MASK, $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $redacted[is_string($key) ? str_replace($needles, self::MASK, $key) : $key] = self::walk($item, $needles);
        }

        return $redacted;
    }

    /**
     * The strings to replace, longest first, so a secret that contains another one is masked whole.
     *
     * @param  list<string>  $secrets
     *
     * @return list<string>
     */
    private static function needles(array $secrets): array
    {
        $needles = [];

        foreach ($secrets as $secret) {
            if (mb_strlen($secret) < self::MIN_SECRET_LENGTH) {
                continue;
            }

            $needles[$secret]               = true;
            $needles[rawurlencode($secret)] = true;
            $needles[urlencode($secret)]    = true;
        }

        $needles = array_map('strval', array_keys($needles));

        usort($needles, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $needles;
    }
}
