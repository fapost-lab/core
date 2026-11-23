<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Flow\Contracts\FlowWebhookEndpointRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\LiveTriggerLookup;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\CurrentAccessState;
use App\Jobs\Flow\StartAutomationRunJob;
use DateTimeImmutable;
use Fapost\Foundation\Channel\Ingress\IngressSpecExecutor;
use Fapost\Foundation\Channel\Ingress\SignedRequest;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Receives one request to a webhook trigger's URL in the current tenant and queues the run it starts.
 *
 * In order: a stopped tenant (503), a multipart body (415), the body size (413), the per-address limit on unknown tokens (429), the endpoint
 * behind the token and its live webhook trigger of a published flow whose draft and assistant still exist (404, one answer for every reason), the
 * per-token limit (429), the signature or secret header through Foundation's {@see IngressSpecExecutor} (401), a flow
 * switched off in the flows list (404, after the signature so that only the secret's holder learns it, counted against
 * no address and the delivery not claimed: nothing is caught up once the flow is turned back on), a trigger
 * the run rate breaker paused (503 with `Retry-After`, the delivery not claimed, so the sender's retry after a resume
 * runs), the delivery id and the fire key it makes, the dedup claim (200 duplicate), and finally {@see StartAutomationRunJob} with
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

    /** How long a sender is asked to wait while the trigger is paused; a person has to resume it. */
    public const int PAUSED_RETRY_AFTER_SECONDS = 300;

    public function __construct(
        private TenantContextInterface $tenantContext,
        private CurrentAccessState $accessState,
        private FlowWebhookEndpointRepositoryInterface $endpoints,
        private LiveTriggerLookup $liveTriggers,
        private FlowDraftRepositoryInterface $drafts,
        private FlowTriggerStateRepositoryInterface $triggerStates,
        private PlatformSettingsReaderInterface $settings,
        private IngressSpecExecutor $specExecutor,
        private InboundRunStarter $starter,
        private RateLimiter $limiter,
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

        // PHP parses a multipart body itself and leaves php://input empty: the flow would get no body, the size check
        // nothing to measure, and a signature nothing to cover.
        if ($request->isMultipart()) {
            $this->logger->info('flow.webhook.unsupported_media_type', ['tenant_id' => $tenantId]);

            return WebhookOutcome::of(WebhookOutcomeStatus::UnsupportedMediaType);
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
        $trigger  = null === $endpoint ? null : $this->liveTriggers->find($tenantId, $endpoint->trigger_id, FlowTriggerType::Webhook, $endpoint->flow_id);
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

        // Not through the live-trigger lookup: that answer counts against the sender's address, and a busy sender of a flow switched
        // off would be held back from the tenant's live flows too.
        if ($this->drafts->isSwitchedOff($trigger->flow_id)) {
            $this->logger->info('flow.webhook.flow_off', $context);

            return WebhookOutcome::of(WebhookOutcomeStatus::NotFound);
        }

        // A cheap read, counted nowhere: run admission refuses the run anyway, but the sender should hear it and retry.
        if ($this->triggerStates->isPaused($trigger->id)) {
            $this->logger->info('flow.webhook.trigger_paused', $context);

            return WebhookOutcome::triggerPaused(self::PAUSED_RETRY_AFTER_SECONDS);
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

        $start = $this->starter->start(
            tenantId: $tenantId,
            trigger: $trigger,
            fireKey: $fireKey,
            deduped: $deduped,
            initialState: WebhookPayload::initialState($request, $config, $shownId, new DateTimeImmutable()),
            sync: $config->isSync(),
            logPrefix: 'flow.webhook',
            logContext: [...$context, 'delivery_id' => $shownId],
        );

        return match ($start->status) {
            InboundRunStartStatus::Duplicate => WebhookOutcome::duplicate($shownId),
            InboundRunStartStatus::Replied   => WebhookOutcome::replied($shownId, $start->reply),
            InboundRunStartStatus::Accepted  => WebhookOutcome::accepted($shownId),
        };
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
