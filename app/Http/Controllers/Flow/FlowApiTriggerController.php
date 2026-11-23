<?php

declare(strict_types=1);

namespace App\Http\Controllers\Flow;

use App\Domains\Flow\Api\ApiTriggerOutcome;
use App\Domains\Flow\Api\ApiTriggerOutcomeStatus;
use App\Domains\Flow\Api\FlowApiTriggerReceiver;
use App\Domains\Flow\Api\InboundApiCall;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\ApiToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /api/v1/flow-triggers/{trigger}/runs` on the tenant's host: an outside system starting a flow through its API
 * trigger, with a tenant API token (the `api-token` stack authenticated it).
 *
 * Thin on purpose — it asks the token for `StartFlowRuns` by value, never a policy
 * (adr-20261119-tenant-api-tokens §7), and {@see FlowApiTriggerReceiver} decides the rest; this turns the outcome into an
 * answer: 202 for a queued run, 200 for a repeated key, or the flow's own status and body in `sync` mode.
 */
final class FlowApiTriggerController extends Controller
{
    public function __invoke(Request $request, string $trigger, FlowApiTriggerReceiver $receiver): Response
    {
        $token = $request->user();

        if (! $token instanceof ApiToken || ! Gate::allows(Permission::StartFlowRuns->value)) {
            return $this->error(403, 'forbidden');
        }

        $outcome = $receiver->receive(new InboundApiCall(
            triggerId: mb_strtolower($trigger),
            contentType: $request->headers->get('Content-Type'),
            rawBody: $request->getContent(),
            idempotencyKey: $request->headers->has('Idempotency-Key') ? (string) $request->headers->get('Idempotency-Key') : null,
            tokenId: (string) $token->getKey(),
            tokenName: $token->name,
        ));

        return match ($outcome->status) {
            ApiTriggerOutcomeStatus::Accepted             => $this->accepted($outcome),
            ApiTriggerOutcomeStatus::Replied              => $this->replied($outcome),
            ApiTriggerOutcomeStatus::Duplicate            => response()->json(['status' => 'duplicate', 'request_id' => $outcome->requestId]),
            ApiTriggerOutcomeStatus::NotFound             => $this->error(404, 'not_found'),
            ApiTriggerOutcomeStatus::UnsupportedMediaType => $this->error(415, 'unsupported_media_type'),
            ApiTriggerOutcomeStatus::PayloadTooLarge      => $this->error(413, 'payload_too_large'),
            ApiTriggerOutcomeStatus::InvalidPayload       => $this->error(422, (string) $outcome->error),
            ApiTriggerOutcomeStatus::TriggerPaused        => $this->error(503, 'trigger_paused', ['Retry-After' => (string) $outcome->retryAfterSeconds]),
        };
    }

    /**
     * The answer to a queued run nobody waited for, or whose flow did not answer in time: 202.
     */
    private function accepted(ApiTriggerOutcome $outcome): JsonResponse
    {
        return response()->json(['status' => 'accepted', 'request_id' => $outcome->requestId], 202);
    }

    /**
     * The flow's answer as it is, with only its content type: no header of it comes from the flow.
     */
    private function replied(ApiTriggerOutcome $outcome): Response
    {
        $reply = $outcome->reply;

        if (null === $reply) {
            return $this->accepted($outcome);
        }

        return response($reply->body, $reply->status, ['Content-Type' => $reply->contentType()]);
    }

    /**
     * The same shape as the stack's own refusals (`{"error":"unauthenticated"}`): a code, never the reason behind it.
     *
     * @param  array<string, string>  $headers
     */
    private function error(int $status, string $code, array $headers = []): JsonResponse
    {
        return response()->json(['error' => $code], $status, $headers);
    }
}
