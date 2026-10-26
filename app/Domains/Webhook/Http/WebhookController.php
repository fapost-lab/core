<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http;

use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use Fapost\Foundation\Channel\Ingress\IngressSpecExecutor;
use Fapost\Foundation\Channel\Ingress\SignedRequest;
use Fapost\Foundation\DTO\InboundWebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;

/**
 * Stateless webhook ingress endpoint. Responsible ONLY for:
 *   1. Signature verification, with the freshness window of the spec (secret from Redis registry, no DB).
 *   2. The provider's handshake, answered in plain text after a verified signature, without a job.
 *   3. Idempotency deduplication (Redis SET NX).
 *   4. Routing envelope lookup (Redis registry, no DB).
 *   5. Dispatching IncomingMessageJob with the request document (the JSON body, or the JSON a form field holds).
 *
 * No Eloquent, no TenantContext, no payload normalization here.
 * All domain work (normalize, contact resolve, flow execution) happens in the worker.
 *
 * Verification runs through the adapter's declarative IngressSpec, so this
 * controller and any external ingress gateway execute byte-identical rules.
 * An adapter ships no verification code.
 */
final class WebhookController extends Controller
{
    public function __construct(
        private readonly ChannelAdapterResolver $adapterResolver,
        private readonly WebhookRegistryResolverInterface $registryResolver,
        private readonly IngressSpecExecutor $specExecutor,
    ) {
    }

    public function __invoke(Request $request, string $channel, string $hash): JsonResponse|Response
    {
        try {
            return $this->handle($request, $hash);
        } catch (InvalidSignatureException|AdapterNotFoundException|WebhookRegistryException $exception) {
            report($exception);

            return response()->json(['ok' => true]);
        }
    }

    private function handle(Request $request, string $hash): JsonResponse|Response
    {
        $entry         = $this->registryResolver->resolve($hash);
        $spec          = $this->adapterResolver->resolve($entry->platform)->ingressSpec();
        $signedRequest = $this->signedRequest($request);

        if (! $this->specExecutor->verify($spec, $signedRequest, $entry->secretToken)) {
            throw new InvalidSignatureException('Invalid channel signature.');
        }

        // Only a verified request is answered with a value derived from its body: the provider's challenge is
        // echoed for whoever holds the signing secret, and for no one else.
        $handshake = $this->specExecutor->handshakeResponse($spec, $signedRequest);

        if (null !== $handshake) {
            return response($handshake, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $idempotencyKey = $this->specExecutor->idempotencyKey($spec, $signedRequest, $entry->channelId);

        if (! $this->markProcessed($idempotencyKey)) {
            return response()->json(['ok' => true]);
        }

        dispatch(
            new IncomingMessageJob(
                new InboundWebhookPayload(
                    tenantId: $entry->tenantId,
                    schema: $entry->schema,
                    assistantId: $entry->assistantId,
                    channelId: $entry->channelId,
                    platform: $entry->platform,
                    rawPayload: $this->specExecutor->document($spec, $signedRequest),
                    idempotencyKey: $idempotencyKey,
                    receivedAt: time(),
                )
            )
        )
            ->onQueue('flow.execution');

        return response()->json(['ok' => true]);
    }

    /**
     * Project the framework request onto the transport-agnostic view a spec operates on.
     *
     * The body is taken as raw bytes: HMAC schemes sign what the provider actually
     * sent, and Laravel's decoded array would not round-trip to the same string.
     */
    private function signedRequest(Request $request): SignedRequest
    {
        return SignedRequest::create(
            rawBody: $request->getContent(),
            headers: $request->headers->all(),
            query: array_map(
                strval(...),
                array_filter($request->query->all(), is_scalar(...)),
            ),
        );
    }

    private function markProcessed(string $idempotencyKey): bool
    {
        return (bool)Redis::set(
            "processed:{$idempotencyKey}",
            '1',
            'EX',
            86400,
            'NX',
        );
    }
}
