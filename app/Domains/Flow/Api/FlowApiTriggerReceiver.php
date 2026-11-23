<?php

declare(strict_types=1);

namespace App\Domains\Flow\Api;

use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\LiveTriggerLookup;
use App\Domains\Flow\Webhook\WebhookDeliveryDedupInterface;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Domains\Flow\Webhook\WebhookSyncWait;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Jobs\Flow\StartAutomationRunJob;
use DateTimeImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Receives one call to an API trigger in the current tenant, from a caller the `api-token` stack already
 * authenticated and the controller already checked for `StartFlowRuns`, and queues the run it starts.
 *
 * In order: a body not declared JSON (415), the body size (413), a body that is not a JSON object (422), the live API
 * trigger of a published flow whose draft and assistant still exist (404, one answer for every reason), the
 * `Idempotency-Key` (422 when it is not 1–255 printable ASCII characters), a flow switched off in the flows list (404,
 * the key not claimed), a trigger the run rate breaker paused (503
 * with `Retry-After`, the call not claimed, so a retry after a resume runs), the dedup claim (200 duplicate, or the kept
 * answer of a `sync` trigger), and finally {@see StartAutomationRunJob} with the call under `flow.api` (202). The run
 * passes run admission in {@see \App\Domains\Flow\Orchestration\AutomationRunner::start()}, as a webhook's does.
 *
 * A trigger in `response_mode: sync` then holds the call for the flow's answer through the webhook's
 * {@see WebhookSyncWait}, with the same slots, ceiling and answers. The stack answers what comes before: a missing or bad
 * token (401), the token's rate limit (429) and a stopped tenant.
 *
 * The reason of a refusal goes to the log (`flow.api.*`), never with the body or a header.
 *
 * Bound scoped: it reads the tenant context.
 */
final readonly class FlowApiTriggerReceiver
{
    /** The largest body a call may carry; it travels in the queued job and the run's state. */
    public const int MAX_BODY_BYTES = 262144;

    /** How long a caller is asked to wait while the trigger is paused; a person has to resume it. */
    public const int PAUSED_RETRY_AFTER_SECONDS = 300;

    public function __construct(
        private TenantContextInterface $tenantContext,
        private LiveTriggerLookup $liveTriggers,
        private FlowDraftRepositoryInterface $drafts,
        private FlowTriggerStateRepositoryInterface $triggerStates,
        private WebhookDeliveryDedupInterface $dedup,
        private WebhookSyncWait $syncWait,
        private WebhookReplyStoreInterface $replies,
        private Dispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function receive(InboundApiCall $call): ApiTriggerOutcome
    {
        $tenantId = (string) $this->tenantContext->get()->getId();
        $context  = ['tenant_id' => $tenantId, 'trigger_id' => $call->triggerId, 'token_id' => $call->tokenId];

        if (! $call->isJson()) {
            $this->logger->info('flow.api.unsupported_media_type', $context);

            return ApiTriggerOutcome::of(ApiTriggerOutcomeStatus::UnsupportedMediaType);
        }

        if (mb_strlen($call->rawBody, '8bit') > self::MAX_BODY_BYTES) {
            $this->logger->info('flow.api.payload_too_large', $context);

            return ApiTriggerOutcome::of(ApiTriggerOutcomeStatus::PayloadTooLarge);
        }

        $body = self::jsonObject($call->rawBody);

        if (null === $body) {
            $this->logger->info('flow.api.invalid_json', $context);

            return ApiTriggerOutcome::invalid('invalid_json');
        }

        $trigger = $this->liveTriggers->find($tenantId, $call->triggerId, FlowTriggerType::Api);
        $config  = null === $trigger ? null : $this->config($trigger);

        if (null === $trigger || null === $config) {
            $this->logger->info('flow.api.not_found', $context);

            return ApiTriggerOutcome::of(ApiTriggerOutcomeStatus::NotFound);
        }

        $context = [...$context, 'flow_id' => $trigger->flow_id, 'trigger_id' => $trigger->id];

        $idempotencyKey = $call->idempotencyKey;

        if (null !== $idempotencyKey && ! ApiFireKey::isValidIdempotencyKey($idempotencyKey)) {
            $this->logger->info('flow.api.invalid_idempotency_key', $context);

            return ApiTriggerOutcome::invalid('invalid_idempotency_key');
        }

        // A flow switched off in the flows list starts nothing (the runner drops it): the caller must not hear 202 and
        // lose its key, so a retry after the switch-on runs.
        if ($this->drafts->isSwitchedOff($trigger->flow_id)) {
            $this->logger->info('flow.api.flow_off', $context);

            return ApiTriggerOutcome::of(ApiTriggerOutcomeStatus::NotFound);
        }

        // A cheap read, counted nowhere: run admission refuses the run anyway, but the caller should hear it and retry.
        if ($this->triggerStates->isPaused($trigger->id)) {
            $this->logger->info('flow.api.trigger_paused', $context);

            return ApiTriggerOutcome::triggerPaused(self::PAUSED_RETRY_AFTER_SECONDS);
        }

        $deduped = null !== $idempotencyKey;

        if ($deduped) {
            $requestId = $idempotencyKey;
            $fireKey   = ApiFireKey::fromIdempotencyKey($call->tokenId, $idempotencyKey);
        } else {
            // No key: a fresh id, so a resend is a second run.
            $requestId = mb_strtolower((string) Str::ulid());
            $fireKey   = ApiFireKey::fresh($requestId);
            $this->logger->info('flow.api.no_idempotency_key', [...$context, 'request_id' => $requestId]);
        }

        if ($deduped && ! $this->dedup->claim($tenantId, $trigger->id, $fireKey)) {
            $this->logger->info('flow.api.duplicate', [...$context, 'request_id' => $requestId]);

            // Never a second wait: it would take the wake-up the first call is waiting for.
            $kept = $config->isSync() ? $this->replies->find($tenantId, $trigger->id, $fireKey) : null;

            return null === $kept ? ApiTriggerOutcome::duplicate($requestId) : ApiTriggerOutcome::replied($requestId, $kept);
        }

        try {
            $this->dispatcher->dispatch(new StartAutomationRunJob(
                tenantId: $tenantId,
                flowId: $trigger->flow_id,
                triggerId: $trigger->id,
                fireKey: $fireKey,
                initialState: ApiPayload::initialState($call, $body, $requestId, new DateTimeImmutable()),
                assistantId: $trigger->assistant_id,
            ));
        } catch (Throwable $exception) {
            if ($deduped) {
                $this->dedup->release($tenantId, $trigger->id, $fireKey);
            }

            throw $exception;
        }

        $this->logger->info('flow.api.accepted', [...$context, 'request_id' => $requestId]);

        if (! $config->isSync()) {
            return ApiTriggerOutcome::accepted($requestId);
        }

        $reply = $this->syncWait->await($tenantId, $trigger->id, $fireKey, [...$context, 'request_id' => $requestId]);

        return null === $reply ? ApiTriggerOutcome::accepted($requestId) : ApiTriggerOutcome::replied($requestId, $reply);
    }

    /**
     * The body as an array when it is a JSON object, else null: a list, a scalar or `null` gives a flow nothing to
     * address by name. Decoded twice — once to tell `{}` from `[]`, which both become an empty PHP array.
     *
     * @return array<string, mixed>|null
     */
    private static function jsonObject(string $rawBody): ?array
    {
        try {
            $decoded = json_decode($rawBody, false, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! $decoded instanceof stdClass) {
            return null;
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        return $body;
    }

    /**
     * A live row was validated when it was published; one an older validator let through (`route_key`,
     * `allowed_sources`) cannot be served, and is treated as no trigger at all.
     */
    private function config(FlowTrigger $trigger): ?ApiTriggerConfig
    {
        try {
            return ApiTriggerConfig::fromConfig(is_array($trigger->config) ? $trigger->config : []);
        } catch (InvalidArgumentException) {
            $this->logger->warning('flow.api.unusable_config', [
                'flow_id'    => $trigger->flow_id,
                'trigger_id' => $trigger->id,
            ]);

            return null;
        }
    }
}
