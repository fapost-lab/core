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
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /hooks/flow/{token}` on the tenant's host: an outside system starting a flow through its webhook trigger.
 *
 * Thin on purpose — {@see FlowWebhookReceiver} decides, and holds a `sync` trigger's request for the flow's answer;
 * this turns the outcome into an answer: 202 for a queued run, or the flow's own status and body.
 */
final class FlowWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, FlowWebhookReceiver $receiver): Response
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
            WebhookOutcomeStatus::Replied          => $this->replied($outcome),
            WebhookOutcomeStatus::Duplicate        => response()->json(['status' => 'duplicate', 'delivery_id' => $outcome->deliveryId]),
            WebhookOutcomeStatus::NotFound         => $this->bare(404),
            WebhookOutcomeStatus::InvalidSignature => $this->bare(401),
            WebhookOutcomeStatus::PayloadTooLarge  => $this->bare(413),
            WebhookOutcomeStatus::Throttled        => $this->bare(429, ['Retry-After' => (string) $outcome->retryAfterSeconds]),
            WebhookOutcomeStatus::TenantStopped    => $this->bare(503, ['Retry-After' => (string) $outcome->retryAfterSeconds]),
        };
    }

    /**
     * The answer to a queued run nobody waited for, or whose flow did not answer in time: 202.
     */
    private function accepted(WebhookOutcome $outcome): JsonResponse
    {
        return response()->json(['status' => 'accepted', 'delivery_id' => $outcome->deliveryId], 202);
    }

    /**
     * The flow's answer as it is, with only its content type: no header of it comes from the flow.
     */
    private function replied(WebhookOutcome $outcome): Response
    {
        $reply = $outcome->reply;

        if (null === $reply) {
            return $this->accepted($outcome);
        }

        return response($reply->body, $reply->status, ['Content-Type' => $reply->contentType()]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function bare(int $status, array $headers = []): JsonResponse
    {
        return response()->json(['status' => 'rejected'], $status, $headers);
    }
}
