<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http;

use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Ingress\IngressSpecExecutor;
use Fapost\Foundation\Channel\Ingress\SignedRequest;
use Fapost\Foundation\DTO\InboundWebhookPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;

/**
 * Stateless webhook ingress endpoint. Responsible ONLY for:
 *   1. Signature verification (secret from Redis registry, no DB).
 *   2. Idempotency deduplication (Redis SET NX).
 *   3. Routing envelope lookup (Redis registry, no DB).
 *   4. Dispatching IncomingMessageJob with raw payload.
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

    public function __invoke(Request $request, string $channel, string $hash): JsonResponse
    {
        try {
            return $this->handle($request, $hash);
        } catch (InvalidSignatureException|AdapterNotFoundException|WebhookRegistryException $exception) {
            report($exception);

            return response()->json(['ok' => true]);
        }
    }

    private function handle(Request $request, string $hash): JsonResponse
    {
        $entry   = $this->registryResolver->resolve($hash);
        $adapter = $this->adapterResolver->resolve($entry->platform);

        if (! $this->verify($adapter, $request, $entry->secretToken)) {
            throw new InvalidSignatureException('Invalid channel signature.');
        }

        $idempotencyKey = $this->idempotencyKey($adapter, $request, $entry->channelId);

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
                    rawPayload: $request->json()->all(),
                    idempotencyKey: $idempotencyKey,
                    receivedAt: time(),
                )
            )
        )
            ->onQueue('flow.execution');

        return response()->json(['ok' => true]);
    }

    /**
     * Verify through the adapter's declarative spec.
     *
     * The spec executor refuses an empty secret itself, for every scheme but `none`.
     */
    private function verify(ChannelInboundAdapterInterface $adapter, Request $request, string $secret): bool
    {
        return $this->specExecutor->verify(
            $adapter->ingressSpec(),
            $this->signedRequest($request),
            $secret,
        );
    }

    private function idempotencyKey(ChannelInboundAdapterInterface $adapter, Request $request, string $channelId): string
    {
        return $this->specExecutor->idempotencyKey(
            $adapter->ingressSpec(),
            $this->signedRequest($request),
            $channelId,
        );
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
