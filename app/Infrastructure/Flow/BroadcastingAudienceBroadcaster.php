<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Broadcasting\DTOs\AutomationBroadcastRequest;
use App\Domains\Broadcasting\Enums\AutomationBroadcastOutcome;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Services\AutomationBroadcasts;
use App\Domains\Flow\Contracts\AudienceBroadcasterInterface;
use App\Domains\Flow\DTOs\AudienceBroadcastRequest;
use App\Domains\Flow\DTOs\AudienceBroadcastResult;
use App\Domains\Flow\Enums\AudienceBroadcastOutcome;
use App\Domains\Flow\Enums\AudienceKind;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * The seam between a flow's `send_message` node and Broadcasting: the node's request becomes a broadcast through
 * {@see AutomationBroadcasts}, the automation's entry, which neither audits nor asks for the current assistant.
 *
 * Bound per resolve, never as a singleton: it and {@see AutomationBroadcasts} read the current tenant.
 */
final readonly class BroadcastingAudienceBroadcaster implements AudienceBroadcasterInterface
{
    public function __construct(
        private AutomationBroadcasts $broadcasts,
        private TenantContextInterface $tenants,
    ) {
    }

    public function start(AudienceBroadcastRequest $request): AudienceBroadcastResult
    {
        $result = $this->broadcasts->start(new AutomationBroadcastRequest(
            tenantId: $request->tenantId,
            assistantId: $request->assistantId,
            name: $request->name,
            target: match ($request->audience) {
                AudienceKind::Tags    => BroadcastTarget::Tags,
                AudienceKind::Segment => BroadcastTarget::Segment,
            },
            tags: $request->tags,
            segmentId: $request->segmentId,
            message: $request->message,
            flowId: $request->flowId,
            flowSessionId: $request->sessionId,
            flowNodeId: $request->nodeId,
            originKey: $request->originKey,
            chainDepth: $request->chainDepth,
        ));

        return new AudienceBroadcastResult(
            outcome: match ($result->outcome) {
                AutomationBroadcastOutcome::Started         => AudienceBroadcastOutcome::Started,
                AutomationBroadcastOutcome::Replayed        => AudienceBroadcastOutcome::Replayed,
                AutomationBroadcastOutcome::Busy            => AudienceBroadcastOutcome::Busy,
                AutomationBroadcastOutcome::AudienceMissing => AudienceBroadcastOutcome::AudienceMissing,
                AutomationBroadcastOutcome::MessageEmpty    => AudienceBroadcastOutcome::MessageEmpty,
            },
            broadcastId: $result->broadcastId,
            recipientCount: $result->recipientCount,
        );
    }

    public function segmentExists(string $segmentId): bool
    {
        if (! $this->tenants->isResolved()) {
            return false;
        }

        return $this->broadcasts->segmentExists((string) $this->tenants->get()->getId(), $segmentId);
    }
}
