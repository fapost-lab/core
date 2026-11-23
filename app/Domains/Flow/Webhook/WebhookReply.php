<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use InvalidArgumentException;
use JsonException;

/**
 * What a flow answers a webhook sender that waits for it (`response_mode: sync`): a status, a body and its type.
 *
 * Made by the `webhook_response` node or, when the run ends without one, by the end of the run ({@see self::forRunEnd()}).
 * No header of the answer is configurable — in particular no `Set-Cookie`; the content type is `application/json` or
 * `text/plain`.
 */
final readonly class WebhookReply
{
    public const int MIN_STATUS = 200;

    /** The highest status a `webhook_response` node may answer with: a flow answers success or a client error. */
    public const int MAX_NODE_STATUS = 499;

    /** The highest status of any reply: above the node's ceiling, the run's end answers a failed run with 500. */
    public const int MAX_REPLY_STATUS = 599;

    /** The largest body a flow may answer with, rendered. */
    public const int MAX_BODY_BYTES = 65536;

    public function __construct(
        public int $status,
        public string $body,
        public WebhookReplyType $type,
    ) {
        if ($status < self::MIN_STATUS || $status > self::MAX_REPLY_STATUS) {
            throw new InvalidArgumentException('A webhook reply status must be ' . self::MIN_STATUS . ' to ' . self::MAX_REPLY_STATUS . ", {$status} given.");
        }
    }

    /**
     * The answer when the run ended without a `webhook_response` node: how it ended, never more. A run that failed is
     * 500; one that completed, or that an `end` node closed as cancelled — the flow's own choice, not an error — is 200.
     */
    public static function forRunEnd(WebhookRunEnd $end): self
    {
        return new self(
            WebhookRunEnd::Failed === $end ? 500 : 200,
            '{"status":"' . $end->value . '"}',
            WebhookReplyType::Json,
        );
    }

    /** The reply as {@see self::toJson()} wrote it, or null for anything else. */
    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $type = is_array($data) ? WebhookReplyType::fromValue($data['type'] ?? null) : null;

        if (null === $type || ! is_int($data['status'] ?? null) || ! is_string($data['body'] ?? null)) {
            return null;
        }

        try {
            return new self($data['status'], $data['body'], $type);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function contentType(): string
    {
        return $this->type->contentType();
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return json_encode(['status' => $this->status, 'body' => $this->body, 'type' => $this->type->value], JSON_THROW_ON_ERROR);
    }
}
