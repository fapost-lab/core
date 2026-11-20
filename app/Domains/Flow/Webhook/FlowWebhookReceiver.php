<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\FlowWebhookEndpointRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Models\FlowWebhookEndpoint;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\CurrentAccessState;
use App\Jobs\Flow\StartAutomationRunJob;
use DateTimeImmutable;
use Fapost\Foundation\Channel\Ingress\IngressSpecExecutor;
use Fapost\Foundation\Channel\Ingress\SignedRequest;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Receives one request to a webhook trigger's URL in the current tenant and queues the run it starts.
 *
 * In order: a stopped tenant (503), the body size (413), the per-address limit on unknown tokens (429), the endpoint
 * behind the token and its live webhook trigger of a published flow whose draft and assistant still exist (404, one answer for every reason), the
 * per-token limit (429), the signature or secret header through Foundation's {@see IngressSpecExecutor} (401), the
 * delivery id and the fire key it makes, the dedup claim (200 duplicate), and finally {@see StartAutomationRunJob} with
 * the request under `flow.webhook` (202). Only an accepted request reaches the queue.
 *
 * A trigger in `response_mode: sync` then holds the request for the flow's answer through {@see WebhookSyncWait} (the
 * answer, or 202 when there is no wait slot or no answer in time); a repeated delivery of such a trigger is answered
 * with the kept answer while it lives, and never waits itself.
 *
 * Every answer except 202 and 200 says nothing beyond its code; the reason goes to the log (`flow.webhook.*`), never
 * with the body, the secret or the signature.
 *
 * Bound scoped: it reads the tenant context, the request's access state and platform settings.
 */
final readonly class FlowWebhookReceiver
{
    /** The largest body a webhook may carry; it travels in the queued job and the run's state. */
    public const int MAX_BODY_BYTES = 262144;

    /** Requests a minute one address may make with tokens that lead nowhere, before it is answered 429. */
    public const int UNKNOWN_TOKENS_PER_MINUTE = 60;

    public const string PER_TOKEN_SETTING = 'flow.webhook.per_token_per_minute';

    /** How long a sender is asked to wait while the tenant is stopped; the dedup window covers its retry. */
    public const int STOPPED_RETRY_AFTER_SECONDS = 300;

    public function __construct(
        private TenantContextInterface $tenantContext,
        private CurrentAccessState $accessState,
        private FlowWebhookEndpointRepositoryInterface $endpoints,
        private FlowTriggerRepositoryInterface $triggers,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowDraftRepositoryInterface $drafts,
        private PlatformSettingsReaderInterface $settings,
        private IngressSpecExecutor $specExecutor,
        private WebhookDeliveryDedupInterface $dedup,
        private WebhookSyncWait $syncWait,
        private WebhookReplyStoreInterface $replies,
        private RateLimiter $limiter,
        private Dispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function receive(InboundWebhook $request): WebhookOutcome
    {
        $tenantId = (string) $this->tenantContext->get()->getId();

        if ($this->accessState->get()->isStopped()) {
            $this->logger->info('flow.webhook.tenant_stopped', ['tenant_id' => $tenantId]);

            return WebhookOutcome::tenantStopped(self::STOPPED_RETRY_AFTER_SECONDS);
        }

        if (mb_strlen($request->rawBody, '8bit') > self::MAX_BODY_BYTES) {
            return WebhookOutcome::of(WebhookOutcomeStatus::PayloadTooLarge);
        }

        // Counted only for tokens that lead nowhere, and checked before the lookup: guessing tokens costs a guesser
        // its address, while a busy sender with a real token is held only by its token's limit below.
        // Per tenant and address: one tenant's dead URL retried from a provider's shared addresses must not hold back
        // the same provider's deliveries to other tenants.
        $missKey = 'flow-webhook:miss:' . $tenantId . ':' . ($request->clientIp ?? 'unknown');

        if ($this->limiter->tooManyAttempts($missKey, self::UNKNOWN_TOKENS_PER_MINUTE)) {
            $this->logger->info('flow.webhook.throttled', ['tenant_id' => $tenantId, 'limit' => 'unknown_tokens']);

            return WebhookOutcome::throttled($this->limiter->availableIn($missKey));
        }

        $endpoint = $this->endpoints->findByToken($tenantId, $request->token);
        $trigger  = null === $endpoint ? null : $this->liveTrigger($endpoint);
        $config   = null === $trigger ? null : $this->config($trigger);

        if (null === $endpoint || null === $trigger || null === $config) {
            $this->limiter->hit($missKey, 60);
            $this->logger->info('flow.webhook.not_found', [
                'tenant_id'  => $tenantId,
                'trigger_id' => $endpoint?->trigger_id,
            ]);

            return WebhookOutcome::of(WebhookOutcomeStatus::NotFound);
        }

        $context = ['tenant_id' => $tenantId, 'flow_id' => $trigger->flow_id, 'trigger_id' => $trigger->id];

        $tokenKey = 'flow-webhook:token:' . $tenantId . ':' . $endpoint->id;

        if ($this->limiter->tooManyAttempts($tokenKey, max(1, $this->settings->integer(self::PER_TOKEN_SETTING)))) {
            $this->logger->info('flow.webhook.throttled', [...$context, 'limit' => 'per_token']);

            return WebhookOutcome::throttled($this->limiter->availableIn($tokenKey));
        }

        $this->limiter->hit($tokenKey, 60);

        $spec   = $config->ingressSpec();
        $signed = SignedRequest::create(
            rawBody: $request->rawBody,
            headers: $request->headers,
            query: [],
        );

        // No secret yet (the URL was made, the secret never generated) refuses every request, as a wrong one does.
        if (! $endpoint->hasSecret() || ! $this->specExecutor->verify($spec, $signed, (string) $endpoint->secret)) {
            $this->logger->info('flow.webhook.rejected_signature', $context);

            return WebhookOutcome::of(WebhookOutcomeStatus::InvalidSignature);
        }

        $deliveryId = $this->specExecutor->idempotencyKey($spec, $signed, '');
        $deduped    = '' !== $deliveryId;

        if (! $deduped) {
            // The trigger names no delivery id, or this request lacks it: a fresh id, so a resend is a second run.
            $deliveryId = mb_strtolower((string) Str::ulid());
            $this->logger->warning('flow.webhook.no_delivery_id', $context);
        }

        $fireKey = WebhookFireKey::fromDeliveryId($deliveryId);
        $shownId = WebhookFireKey::shownId($fireKey);

        if ($deduped && ! $this->dedup->claim($tenantId, $trigger->id, $fireKey)) {
            $this->logger->info('flow.webhook.duplicate', [...$context, 'delivery_id' => $shownId]);

            // Never a second wait: it would take the wake-up the first request is waiting for.
            $kept = $config->isSync() ? $this->replies->find($tenantId, $trigger->id, $fireKey) : null;

            return null === $kept ? WebhookOutcome::duplicate($shownId) : WebhookOutcome::replied($shownId, $kept);
        }

        try {
            $this->dispatcher->dispatch(new StartAutomationRunJob(
                tenantId: $tenantId,
                flowId: $trigger->flow_id,
                triggerId: $trigger->id,
                fireKey: $fireKey,
                initialState: WebhookPayload::initialState($request, $config, $shownId, new DateTimeImmutable()),
                assistantId: $trigger->assistant_id,
            ));
        } catch (Throwable $exception) {
            if ($deduped) {
                $this->dedup->release($tenantId, $trigger->id, $fireKey);
            }

            throw $exception;
        }

        $this->logger->info('flow.webhook.accepted', [...$context, 'delivery_id' => $shownId]);

        if (! $config->isSync()) {
            return WebhookOutcome::accepted($shownId);
        }

        $reply = $this->syncWait->await($tenantId, $trigger->id, $fireKey, [...$context, 'delivery_id' => $shownId]);

        return null === $reply ? WebhookOutcome::accepted($shownId) : WebhookOutcome::replied($shownId, $reply);
    }

    /**
     * The endpoint's trigger as it is live now: a `flow_triggers` row of the endpoint's flow, of type webhook and
     * active, whose flow has a published version. A trigger only saved in a draft has no row yet.
     */
    private function liveTrigger(FlowWebhookEndpoint $endpoint): ?FlowTrigger
    {
        foreach ($this->triggers->getByFlowId($endpoint->flow_id) as $row) {
            if (mb_strtolower($row->id) !== mb_strtolower($endpoint->trigger_id)) {
                continue;
            }

            if (FlowTriggerType::Webhook !== $row->type || ! $row->is_active) {
                return null;
            }

            if (! $this->flowIsManaged($row->flow_id)) {
                return null;
            }

            return null === $this->definitions->findLatestActiveByFlowId($row->flow_id) ? null : $row;
        }

        return null;
    }

    /**
     * Whether someone can still manage the flow: its draft and the draft's assistant exist. A deleted flow or assistant
     * leaves its definitions and trigger rows behind, but a URL nobody can revoke must not start runs.
     */
    private function flowIsManaged(string $flowId): bool
    {
        try {
            return null !== $this->drafts->findByFlowId($flowId)->assistant;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    /**
     * A live row was validated when it was published; one an older validator let through cannot be checked, and is
     * treated as no trigger at all.
     */
    private function config(FlowTrigger $trigger): ?WebhookTriggerConfig
    {
        try {
            return WebhookTriggerConfig::fromConfig(is_array($trigger->config) ? $trigger->config : []);
        } catch (InvalidArgumentException) {
            $this->logger->warning('flow.webhook.unusable_config', [
                'flow_id'    => $trigger->flow_id,
                'trigger_id' => $trigger->id,
            ]);

            return null;
        }
    }
}
