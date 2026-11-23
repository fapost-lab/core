<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Flow\Models\FlowTrigger;
use App\Jobs\Flow\StartAutomationRunJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tail the webhook and the API receivers share, once the call passed every check: the dedup claim, the run's
 * queueing and, for a trigger in `response_mode: sync`, the wait for the flow's answer.
 *
 * The claim is made only when the call carries a key of its own (`$deduped`); a lost one is a duplicate, answered with
 * the kept answer of a `sync` trigger and never with a second wait, which would take the wake-up the first call is
 * waiting for. A claim is released when the queueing fails, so the caller's retry is not taken for a duplicate.
 * {@see StartAutomationRunJob} then carries the call under `flow.<trigger type>`; run admission is asked when it runs.
 *
 * The receiver names the log keys (`<prefix>.duplicate`, `<prefix>.accepted`) and the context both lines and the wait
 * share, so each trigger type keeps its own and the reason of an answer never goes to the caller.
 */
final readonly class InboundRunStarter
{
    public function __construct(
        private WebhookDeliveryDedupInterface $dedup,
        private WebhookReplyStoreInterface $replies,
        private WebhookSyncWait $syncWait,
        private Dispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  array<string, mixed>  $initialState  the call under its `flow.*` namespace
     * @param  string                $logPrefix     `flow.api` or `flow.webhook`
     * @param  array<string, mixed>  $logContext    what every line and the sync wait log, with the call's id in it
     */
    public function start(
        string $tenantId,
        FlowTrigger $trigger,
        string $fireKey,
        bool $deduped,
        array $initialState,
        bool $sync,
        string $logPrefix,
        array $logContext,
    ): InboundRunStart {
        if ($deduped && ! $this->dedup->claim($tenantId, $trigger->id, $fireKey)) {
            $this->logger->info($logPrefix . '.duplicate', $logContext);

            // Never a second wait: it would take the wake-up the first call is waiting for.
            $kept = $sync ? $this->replies->find($tenantId, $trigger->id, $fireKey) : null;

            return null === $kept ? InboundRunStart::duplicate() : InboundRunStart::replied($kept);
        }

        try {
            $this->dispatcher->dispatch(new StartAutomationRunJob(
                tenantId: $tenantId,
                flowId: $trigger->flow_id,
                triggerId: $trigger->id,
                fireKey: $fireKey,
                initialState: $initialState,
                assistantId: $trigger->assistant_id,
            ));
        } catch (Throwable $exception) {
            if ($deduped) {
                $this->dedup->release($tenantId, $trigger->id, $fireKey);
            }

            throw $exception;
        }

        $this->logger->info($logPrefix . '.accepted', $logContext);

        if (! $sync) {
            return InboundRunStart::accepted();
        }

        $reply = $this->syncWait->await($tenantId, $trigger->id, $fireKey, $logContext);

        return null === $reply ? InboundRunStart::accepted() : InboundRunStart::replied($reply);
    }
}
