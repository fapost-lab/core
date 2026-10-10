<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Support;

/**
 * Builds the unit key of a per-period usage unit from a prefix and the caller's natural key.
 *
 * The usage contract accepts at most 191 characters, while natural keys (idempotency keys, engine
 * execution keys) are not bounded; a pair that does not fit becomes the prefix and the SHA-256 of
 * the natural key. The same input always gives the same output, as a retried unit requires.
 */
final class UsageUnitKey
{
    private const int MAX_LENGTH = 191;

    public static function make(string $prefix, string $naturalKey): string
    {
        $unitKey = $prefix . $naturalKey;

        return mb_strlen($unitKey) <= self::MAX_LENGTH ? $unitKey : $prefix . hash('sha256', $naturalKey);
    }
}
