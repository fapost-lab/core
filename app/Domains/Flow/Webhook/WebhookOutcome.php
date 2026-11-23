<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

final readonly class WebhookOutcome
{
    private function __construct(
        public WebhookOutcomeStatus $status,
        public ?string $deliveryId = null,
        public ?int $retryAfterSeconds = null,
        public ?WebhookReply $reply = null,
    ) {
    }

    /** The flow answered a sender that waited for it (`response_mode: sync`). */
    public static function replied(string $deliveryId, WebhookReply $reply): self
    {
        return new self(WebhookOutcomeStatus::Replied, $deliveryId, null, $reply);
    }

    public static function accepted(string $deliveryId): self
    {
        return new self(WebhookOutcomeStatus::Accepted, $deliveryId);
    }

    public static function duplicate(string $deliveryId): self
    {
        return new self(WebhookOutcomeStatus::Duplicate, $deliveryId);
    }

    public static function throttled(int $retryAfterSeconds): self
    {
        return new self(WebhookOutcomeStatus::Throttled, null, max(1, $retryAfterSeconds));
    }

    public static function tenantStopped(int $retryAfterSeconds): self
    {
        return new self(WebhookOutcomeStatus::TenantStopped, null, $retryAfterSeconds);
    }

    public static function triggerPaused(int $retryAfterSeconds): self
    {
        return new self(WebhookOutcomeStatus::TriggerPaused, null, $retryAfterSeconds);
    }

    public static function of(WebhookOutcomeStatus $status): self
    {
        return new self($status);
    }
}
