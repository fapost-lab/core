<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Admission\TriggerPauseService;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Resumes a flow's trigger that the run rate breaker paused, from the builder.
 *
 * Starting runs again takes what turning on an automation takes (`resumeTrigger`: editing the flow and the right to
 * manage automations). The answer is the flow's trigger states as they are after the resume.
 */
final class TriggerStateController extends Controller
{
    public function resume(string $flow, string $trigger, FlowDraftRepositoryInterface $drafts, TriggerPauseService $pauses): JsonResponse
    {
        $draft = $drafts->findByFlowId($flow);
        Gate::authorize('resumeTrigger', $draft);

        if (! $pauses->resume($draft, $trigger)) {
            return response()->json(['message' => __('builder.trigger_state.not_found')], 404);
        }

        return response()->json(['data' => (object) $pauses->describe($draft->flow_id)]);
    }
}
