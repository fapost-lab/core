<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Webhook\FlowWebhookEndpointService;
use App\Domains\Flow\Webhook\WebhookEndpointUnavailableException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * The URL and the secret of a flow's webhook trigger, from the builder.
 *
 * Making the URL is an edit of the flow (`update`: ManageFlowDefinitions and the assistant); setting the secret hands a
 * secret to an outside system, so it takes what making the trigger live takes (`manageAutomations`: PublishFlow and
 * ManageAutomations). The
 * secret is in the answer only when the server generated it, and only that once.
 */
final class WebhookEndpointController extends Controller
{
    public function store(string $flow, string $trigger, FlowDraftRepositoryInterface $drafts, FlowWebhookEndpointService $endpoints): JsonResponse
    {
        $draft = $drafts->findByFlowId($flow);
        Gate::authorize('update', $draft);

        try {
            return response()->json(['data' => $endpoints->present($endpoints->ensure($draft, $trigger))]);
        } catch (WebhookEndpointUnavailableException) {
            return $this->unavailable();
        }
    }

    public function secret(Request $request, string $flow, string $trigger, FlowDraftRepositoryInterface $drafts, FlowWebhookEndpointService $endpoints): JsonResponse
    {
        $draft = $drafts->findByFlowId($flow);
        Gate::authorize('manageAutomations', $draft);

        $validated = $request->validate(['secret' => ['sometimes', 'nullable', 'string']]);
        $provided  = $validated['secret'] ?? null;

        try {
            if (is_string($provided) && '' !== $provided) {
                $endpoints->setSecret($draft, $trigger, $provided);
                $shown = null;
            } else {
                $shown = $endpoints->generateSecret($draft, $trigger);
            }

            $data = $endpoints->present($endpoints->ensure($draft, $trigger));
        } catch (WebhookEndpointUnavailableException) {
            return $this->unavailable();
        } catch (InvalidArgumentException) {
            $message = __('builder.webhook_trigger.secret_invalid', [
                'min' => FlowWebhookEndpointService::MIN_SECRET_LENGTH,
                'max' => FlowWebhookEndpointService::MAX_SECRET_LENGTH,
            ]);

            return response()->json(['message' => $message, 'errors' => ['secret' => [$message]]], 422);
        }

        return response()->json(['data' => [...$data, 'secret' => $shown]]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => __('builder.webhook_trigger.not_found')], 404);
    }
}
