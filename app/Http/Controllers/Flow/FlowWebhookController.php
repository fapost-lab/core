<?php

declare(strict_types=1);

namespace App\Http\Controllers\Flow;

use App\Domains\Flow\Webhook\FlowWebhookReceiver;
use App\Domains\Flow\Webhook\InboundWebhook;
use App\Domains\Flow\Webhook\WebhookOutcome;
use App\Domains\Flow\Webhook\WebhookOutcomeStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /hooks/flow/{token}` on the tenant's host: an outside system starting a flow through its webhook trigger.
 *
 * Thin on purpose — {@see FlowWebhookReceiver} decides; this turns the outcome into an answer. Only the asynchronous
 * mode exists: an accepted request is answered 202 at once. The synchronous "respond to webhook" mode (task
 * `flow-webhook-sync-response`) plugs in at {@see self::accepted()}.
 */
final class FlowWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, FlowWebhookReceiver $receiver): JsonResponse
    {
        $isForm = str_starts_with(mb_strtolower((string) $request->headers->get('Content-Type', '')), 'application/x-www-form-urlencoded');

        $outcome = $receiver->receive(new InboundWebhook(
            token: $token,
            method: $request->getMethod(),
            rawBody: $request->getContent(),
            headers: $request->headers->all(),
            query: $request->query->all(),
            formFields: $isForm ? $request->request->all() : [],
            clientIp: $request->ip(),
        ));

        return match ($outcome->status) {
            WebhookOutcomeStatus::Accepted         => $this->accepted($outcome),
            WebhookOutcomeStatus::Duplicate        => response()->json(['status' => 'duplicate', 'delivery_id' => $outcome->deliveryId]),
            WebhookOutcomeStatus::NotFound         => $this->bare(404),
            WebhookOutcomeStatus::InvalidSignature => $this->bare(401),
            WebhookOutcomeStatus::PayloadTooLarge  => $this->bare(413),
            WebhookOutcomeStatus::Throttled        => $this->bare(429, ['Retry-After' => (string) $outcome->retryAfterSeconds]),
            WebhookOutcomeStatus::TenantStopped    => $this->bare(503, ['Retry-After' => (string) $outcome->retryAfterSeconds]),
        };
    }

    /**
     * The answer to a queued run: 202 at once. The one place a waiting response would be chosen instead.
     */
    private function accepted(WebhookOutcome $outcome): JsonResponse
    {
        return response()->json(['status' => 'accepted', 'delivery_id' => $outcome->deliveryId], 202);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function bare(int $status, array $headers = []): JsonResponse
    {
        return response()->json(['status' => 'rejected'], $status, $headers);
    }
}
