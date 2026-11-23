<?php

declare(strict_types=1);

namespace App\Domains\Flow\Api;

use App\Domains\Flow\DTOs\FlowRunOrigin;

/**
 * The fire key of a call to an API trigger.
 *
 * With an `Idempotency-Key`: `api:<token id>:<key>`, or `api:sha256:<hex of "token id:key">` when that would not fit
 * {@see FlowRunOrigin::MAX_FIRE_KEY_LENGTH}. The token is part of the key, so two integrations that happen to send the
 * same key start two runs, and one integration's resend starts one. Without a key: `api:<fresh ULID>`, a new run every
 * time.
 */
final class ApiFireKey
{
    /** The longest `Idempotency-Key` accepted, in characters. */
    public const int MAX_IDEMPOTENCY_KEY_LENGTH = 255;
    private const string PREFIX                 = 'api:';

    /** Whether a key the caller sent can be used: 1 to 255 printable ASCII characters. */
    public static function isValidIdempotencyKey(string $key): bool
    {
        return 1 === preg_match('/^[\x20-\x7E]{1,' . self::MAX_IDEMPOTENCY_KEY_LENGTH . '}$/D', $key);
    }

    public static function fromIdempotencyKey(string $tokenId, string $key): string
    {
        $fireKey = self::PREFIX . mb_strtolower($tokenId) . ':' . $key;

        if (mb_strlen($fireKey) <= FlowRunOrigin::MAX_FIRE_KEY_LENGTH) {
            return $fireKey;
        }

        return self::PREFIX . 'sha256:' . hash('sha256', mb_strtolower($tokenId) . ':' . $key);
    }

    public static function fresh(string $requestId): string
    {
        return self::PREFIX . $requestId;
    }

    /** Whether a run's fire key is an API call's: only such a run has a caller that may be waiting for it. */
    public static function isApiFireKey(string $fireKey): bool
    {
        return str_starts_with($fireKey, self::PREFIX);
    }
}
