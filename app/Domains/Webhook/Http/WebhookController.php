<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http;

use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use FAPost\Foundation\DTO\InboundWebhookPayload;
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
 */
final class WebhookController extends Controller
{
    public function __construct(
        private readonly ChannelAdapterResolver $adapterResolver,
        private readonly WebhookRegistryResolverInterface $registryResolver,
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

        if ( ! $adapter->verifySignature($request, $entry->secretToken)) {
            throw new InvalidSignatureException('Invalid channel signature.');
        }

        $idempotencyKey = $adapter->extractIdempotencyKey($request, $entry->channelId);

        if ( ! $this->markProcessed($idempotencyKey)) {
            return response()->json(['ok' => true]);
        }

        dispatch(
            new IncomingMessageJob(
                new InboundWebhookPayload(
                    tenantId: $entry->tenantId,
                    schema: $entry->schema,
                    assistantId: $entry->assistantId,
                    channelId: $entry->channelId,
                    platform: $entry->platform->value,
                    rawPayload: $request->json()->all(),
                    idempotencyKey: $idempotencyKey,
                    receivedAt: time(),
                )
            )
        )
            ->onQueue('flow.execution');

        return response()->json(['ok' => true]);
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
