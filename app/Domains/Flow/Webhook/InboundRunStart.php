<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

/**
 * The result of {@see InboundRunStarter::start()}: the status, and the flow's answer when it is {@see InboundRunStartStatus::Replied}.
 */
final readonly class InboundRunStart
{
    private function __construct(
        public InboundRunStartStatus $status,
        public ?WebhookReply $reply = null,
    ) {
    }

    public static function duplicate(): self
    {
        return new self(InboundRunStartStatus::Duplicate);
    }

    public static function accepted(): self
    {
        return new self(InboundRunStartStatus::Accepted);
    }

    public static function replied(WebhookReply $reply): self
    {
        return new self(InboundRunStartStatus::Replied, $reply);
    }
}
