<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Services\LoadBuilderFlowService;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\SaveDraftService;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveDraftRequest;
use App\Http\Requests\ValidateFlowRequest;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

final class BuilderFlowController extends Controller
{
    public function show(
        string $flow,
        LoadBuilderFlowService $service,
    ): Response {
        $dto = $service->execute(flowId: $flow);

        return Inertia::render('FlowBuilder/FlowEditor', [
            'flow' => [
                'flowId'           => $dto->flowId,
                'name'             => $dto->name,
                'draftVersion'     => $dto->draftVersion,
                'publishedVersion' => $dto->publishedVersion,
                'definition'       => $dto->definition,
                'publishedAt'      => $dto->publishedAt?->toIso8601String(),
            ],
            'backUrl' => route('filament.assistant.resources.flows.index', ['tenant' => $dto->assistantId]),
        ]);
    }

    public function saveDraft(
        SaveDraftRequest $request,
        string $flow,
        SaveDraftService $service,
    ): JsonResponse {
        $newDraftVersion = $service->execute(
            flowId: $flow,
            nodes: $request->validated('definition'),
            expectedDraftVersion: $request->validated('draft_version'),
        );

        return response()->json(['draft_version' => $newDraftVersion]);
    }

    public function validate(
        ValidateFlowRequest $request,
        string $flow,
        ValidateFlowService $service,
    ): JsonResponse {
        $result = $service->execute(
            nodes: $request->validated('definition'),
        );

        return response()->json($result);
    }

    public function publish(
        string $flow,
        PublishFlowService $service,
    ): JsonResponse {
        $definition = $service->execute(flowId: $flow);

        return response()->json([
            'version'      => $definition->version,
            'published_at' => $definition->published_at,
        ]);
    }
}
