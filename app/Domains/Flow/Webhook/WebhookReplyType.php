<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

/**
 * The type of a {@see WebhookReply}'s body: what the `webhook_response` node's `body_type` names and what decides the
 * answer's content type. The value is also how a kept reply is written to the reply store.
 */
enum WebhookReplyType: string
{
    case Json = 'json';
    case Text = 'text';

    /**
     * The type a value names — a node's `body_type`, a kept reply's `type` — or null for anything else, a value that is
     * not a string included.
     */
    public static function fromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Json => 'application/json',
            self::Text => 'text/plain; charset=UTF-8',
        };
    }
}
