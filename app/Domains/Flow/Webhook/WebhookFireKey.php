<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Flow\DTOs\FlowRunOrigin;

/**
 * The fire key of a webhook delivery: `wh:<delivery id>`, or `wh:sha256:<hex>` when the id would not fit
 * {@see FlowRunOrigin::MAX_FIRE_KEY_LENGTH} or is not valid UTF-8 (it comes from a header or the body as sent).
 */
final class WebhookFireKey
{
    private const string PREFIX = 'wh:';

    public static function fromDeliveryId(string $deliveryId): string
    {
        $key = self::PREFIX . $deliveryId;

        if (mb_check_encoding($deliveryId, 'UTF-8') && mb_strlen($key) <= FlowRunOrigin::MAX_FIRE_KEY_LENGTH && 1 !== preg_match('/[\x00-\x1F\x7F]/', $deliveryId)) {
            return $key;
        }

        return self::PREFIX . 'sha256:' . hash('sha256', $deliveryId);
    }

    /** The delivery id as the sender is told it: the fire key without its prefix. */
    public static function shownId(string $fireKey): string
    {
        return mb_substr($fireKey, mb_strlen(self::PREFIX));
    }
}
