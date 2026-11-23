<?php

declare(strict_types=1);

namespace App\Domains\Flow\Api;

use App\Domains\Flow\Webhook\WebhookReply;

final readonly class ApiTriggerOutcome
{
    private function __construct(
        public ApiTriggerOutcomeStatus $status,
        public ?string $requestId = null,
        public ?int $retryAfterSeconds = null,
        public ?WebhookReply $reply = null,
        public ?string $error = null,
    ) {
    }

    public static function accepted(string $requestId): self
    {
        return new self(ApiTriggerOutcomeStatus::Accepted, $requestId);
    }

    /** The flow answered a caller that waited for it (`response_mode: sync`). */
    public static function replied(string $requestId, WebhookReply $reply): self
    {
        return new self(ApiTriggerOutcomeStatus::Replied, $requestId, null, $reply);
    }

    public static function duplicate(string $requestId): self
    {
        return new self(ApiTriggerOutcomeStatus::Duplicate, $requestId);
    }

    /**
     * @param  string  $error  what was wrong, a code the caller can act on: `invalid_json` or `invalid_idempotency_key`
     */
    public static function invalid(string $error): self
    {
        return new self(ApiTriggerOutcomeStatus::InvalidPayload, null, null, null, $error);
    }

    public static function triggerPaused(int $retryAfterSeconds): self
    {
        return new self(ApiTriggerOutcomeStatus::TriggerPaused, null, $retryAfterSeconds);
    }

    public static function of(ApiTriggerOutcomeStatus $status): self
    {
        return new self($status);
    }
}
