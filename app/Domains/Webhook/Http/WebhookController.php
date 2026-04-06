<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Http;

use App\Domains\Webhook\Contracts\WebhookRegistryResolverInterface;
use App\Domains\Webhook\Exceptions\AdapterNotFoundException;
use App\Domains\Webhook\Exceptions\InvalidSignatureException;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\Jobs\IncomingMessageJob;
use App\Domains\Webhook\Services\ChannelAdapterResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Redis;

final class WebhookController extends Controller
{
    public function __construct(
        private readonly ChannelAdapterResolver $adapterResolver,
        private readonly WebhookRegistryResolverInterface $registryResolver,
    ) {
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function __invoke(Request $request, string $channel, string $hash): JsonResponse
    {
        try {
            return $this->handle($request, $hash);
        } catch (InvalidSignatureException|AdapterNotFoundException|WebhookRegistryException $exception) {
            report($exception);

            return response()->json(['ok' => true]);
        }
    }

    /**
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    private function handle(Request $request, string $hash): JsonResponse
    {
        $entry = $this->registryResolver->resolve($hash);

        $adapter = $this->adapterResolver->resolve($entry->platform);
        $adapter->verifySignature($request, $entry->secretToken);

        $updateId = (string) $request->json('update_id', '');

        if ('' !== $updateId && ! $this->markProcessed($entry->channelId, $updateId)) {
            return response()->json(['ok' => true]);
        }

        $message = $adapter->parse($request);

        IncomingMessageJob::dispatch(
            message: $message,
            tenantId: $entry->tenantId,
            assistantId: $entry->assistantId,
            channelId: $entry->channelId,
            schema: $entry->schema,
        )->onQueue('flow.execution');

        return response()->json(['ok' => true]);
    }

    private function markProcessed(string $channelId, string $updateId): bool
    {
        return (bool) Redis::set(
            "processed:{$channelId}:{$updateId}",
            '1',
            'EX',
            86400,
            'NX',
        );
    }
}
