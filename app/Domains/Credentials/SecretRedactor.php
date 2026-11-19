<?php

declare(strict_types=1);

namespace App\Domains\Credentials;

/**
 * Replaces secrets with `***` in any value a call result is made of: strings, and the keys and values of arrays,
 * at any depth. Each secret is masked as it is and in its URL-encoded forms, the longest first, so a secret that
 * contains another is masked whole. A secret shorter than {@see self::MIN_LENGTH} is skipped: it would mask
 * ordinary text. Anything that is not a string or an array passes untouched.
 *
 * Stateless; separate from the Channels redactor, which also knows the Telegram URL shape.
 */
final class SecretRedactor
{
    public const string MASK = '***';

    public const int MIN_LENGTH = 4;

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
     * @param  list<string>  $needles  longest first
     */
    private static function walk(mixed $value, array $needles): mixed
    {
        if (is_string($value)) {
            return str_replace($needles, self::MASK, $value);
        }

        if (is_array($value)) {
            $redacted = [];

            foreach ($value as $key => $item) {
                $redacted[is_string($key) ? str_replace($needles, self::MASK, $key) : $key] = self::walk($item, $needles);
            }

            return $redacted;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $secrets
     *
     * @return list<string>
     */
    private static function needles(array $secrets): array
    {
        $needles = [];

        foreach ($secrets as $secret) {
            if (!is_string($secret) || mb_strlen($secret, '8bit') < self::MIN_LENGTH) {
                continue;
            }

            foreach ([$secret, rawurlencode($secret), urlencode($secret)] as $form) {
                $needles[$form] = true;
            }
        }

        $needles = array_map('strval', array_keys($needles));

        usort($needles, static fn (string $a, string $b): int => mb_strlen($b, '8bit') <=> mb_strlen($a, '8bit'));

        return $needles;
    }
}
