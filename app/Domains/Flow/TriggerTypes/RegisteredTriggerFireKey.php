<?php

declare(strict_types=1);

namespace App\Domains\Flow\TriggerTypes;

use App\Domains\Flow\DTOs\FlowRunOrigin;

/**
 * The fire key of a registered trigger type's run: `tt:<key the extension gave>`, or `tt:sha256:<hex>` when that key
 * would not fit {@see FlowRunOrigin::MAX_FIRE_KEY_LENGTH}, is not valid UTF-8 or holds a control character. The prefix
 * keeps it apart from the keys of Core events and webhook deliveries.
 */
final class RegisteredTriggerFireKey
{
    private const string PREFIX = 'tt:';

    public static function from(string $key): string
    {
        $fireKey = self::PREFIX . $key;

        if (mb_check_encoding($key, 'UTF-8') && mb_strlen($fireKey) <= FlowRunOrigin::MAX_FIRE_KEY_LENGTH && 1 !== preg_match('/[\x00-\x1F\x7F]/', $key)) {
            return $fireKey;
        }

        return self::PREFIX . 'sha256:' . hash('sha256', $key);
    }
}
