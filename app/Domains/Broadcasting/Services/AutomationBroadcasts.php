<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Services;

use App\Domains\Broadcasting\DTOs\AutomationBroadcastRequest;
use App\Domains\Broadcasting\DTOs\AutomationBroadcastResult;
use App\Domains\Broadcasting\Enums\AutomationBroadcastOutcome;
use App\Domains\Broadcasting\Enums\BroadcastOrigin;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Support\BroadcastMessage;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Tenancy\Settings\TenantSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The automation's way into Broadcasting: a flow's `send_message` node to tags or a segment starts a broadcast here.
 *
 * It is the second entry next to the console's {@see BroadcastService::send()}, and differs from it on purpose:
 *
 *  - there is no revision to confirm and no audit record. What a person confirmed is the flow they published (that
 *    publish needs the right to broadcast and is audited); the run that reaches the node has nobody to confirm anything;
 *  - it does not depend on the current assistant: the broadcast is the flow's assistant's, named by the request;
 *  - a node execution starts at most one broadcast, whatever the retries: the request's `origin_key` is unique, and a
 *    request whose key started a broadcast before gets that broadcast back ({@see AutomationBroadcastOutcome::Replayed});
 *  - a flow node has at most one draft or running broadcast at a time, so a schedule every minute or a loop cannot
 *    pile runs on the same audience ({@see AutomationBroadcastOutcome::Busy}).
 *
 * Both guarantees are unique indexes, so two concurrent requests cannot both pass them; the check before the insert
 * only spares the common case an exception. The broadcast is created as a draft and started at once through
 * {@see BroadcastDispatcher::start()}, which queues the run after the commit, as on the console.
 */
final readonly class AutomationBroadcasts
{
    public function __construct(
        private BroadcastDispatcher $dispatcher,
        private BroadcastRecipientResolver $recipients,
        private TenantSettings $settings,
    ) {
    }

    public function start(AutomationBroadcastRequest $request): AutomationBroadcastResult
    {
        $earlier = $this->findByKey($request->originKey);

        if (null !== $earlier) {
            return $this->named(AutomationBroadcastOutcome::Replayed, $earlier);
        }

        if ($this->hasLiveBroadcast($request->flowId, $request->flowNodeId)) {
            return new AutomationBroadcastResult(AutomationBroadcastOutcome::Busy);
        }

        $tags = self::cleanTags($request->tags);

        if (! $this->audienceExists($request, $tags)) {
            return new AutomationBroadcastResult(AutomationBroadcastOutcome::AudienceMissing);
        }

        $message = BroadcastMessage::clean(
            is_string($request->message) ? [$this->settings->content_base_language => $request->message] : $request->message,
        );

        if (! BroadcastMessage::hasBaseLanguageText($message, $this->settings->content_base_language)) {
            return new AutomationBroadcastResult(AutomationBroadcastOutcome::MessageEmpty);
        }

        try {
            // The insert's own transaction (a savepoint only if a caller has one open): on PostgreSQL a unique violation aborts the
            // transaction it happens in, and the re-read below must run outside it.
            $broadcast = Broadcast::query()->getConnection()->transaction(function () use ($request, $tags, $message): Broadcast {
                $broadcast = Broadcast::query()->create([
                    'tenant_id'         => $request->tenantId,
                    'assistant_id'      => $request->assistantId,
                    'name'              => mb_substr($request->name, 0, 255),
                    'message'           => $message,
                    'target_type'       => $request->target->value,
                    'target_tags'       => BroadcastTarget::Tags === $request->target ? $tags : null,
                    'target_segment_id' => BroadcastTarget::Segment === $request->target ? $request->segmentId : null,
                    'status'            => BroadcastStatus::Draft->value,
                    'origin'            => BroadcastOrigin::Automation->value,
                    'flow_id'           => $request->flowId,
                    'flow_session_id'   => $request->flowSessionId,
                    'flow_node_id'      => $request->flowNodeId,
                    'origin_key'        => $request->originKey,
                    'chain_depth'       => max(0, $request->chainDepth),
                ]);

                $this->dispatcher->start($broadcast);

                return $broadcast;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won: the same node execution (its broadcast is this one's), or another run of the
            // same node (busy).
            $winner = $this->findByKey($request->originKey);

            return null !== $winner
                ? $this->named(AutomationBroadcastOutcome::Replayed, $winner)
                : new AutomationBroadcastResult(AutomationBroadcastOutcome::Busy);
        }

        return $this->named(AutomationBroadcastOutcome::Started, $broadcast);
    }

    /**
     * Whether the segment is one of the tenant's, for the flow's validation of a node's audience.
     */
    public function segmentExists(string $tenantId, string $segmentId): bool
    {
        return Str::isUuid($segmentId) && ContactSegment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey(mb_strtolower($segmentId))
            ->exists();
    }

    /**
     * @param  array<array-key, mixed>  $tags
     *
     * @return list<string>
     */
    private static function cleanTags(array $tags): array
    {
        $clean = [];

        foreach ($tags as $tag) {
            $tag = is_string($tag) ? mb_trim($tag) : '';

            if ('' !== $tag && ! in_array($tag, $clean, true)) {
                $clean[] = $tag;
            }
        }

        return $clean;
    }

    private function named(AutomationBroadcastOutcome $outcome, Broadcast $broadcast): AutomationBroadcastResult
    {
        return new AutomationBroadcastResult(
            outcome: $outcome,
            broadcastId: (string) $broadcast->getKey(),
            recipientCount: $this->recipients->count($broadcast),
        );
    }

    private function findByKey(string $originKey): ?Broadcast
    {
        return Broadcast::query()->where('origin_key', $originKey)->first();
    }

    private function hasLiveBroadcast(string $flowId, string $nodeId): bool
    {
        return Broadcast::query()
            ->where('flow_id', $flowId)
            ->where('flow_node_id', $nodeId)
            ->whereIn('status', [BroadcastStatus::Draft->value, BroadcastStatus::Running->value])
            ->exists();
    }

    /**
     * @param  list<string>  $tags
     */
    private function audienceExists(AutomationBroadcastRequest $request, array $tags): bool
    {
        return match ($request->target) {
            BroadcastTarget::Tags    => [] !== $tags,
            BroadcastTarget::Segment => null !== $request->segmentId && $this->segmentExists($request->tenantId, $request->segmentId),
            // Everyone is not an audience a flow may name.
            BroadcastTarget::All => false,
        };
    }
}
