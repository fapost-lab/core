<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Exceptions\InvalidTriggerPayloadException;
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
                'flowId'              => $dto->flowId,
                'name'                => $dto->name,
                'draftVersion'        => $dto->draftVersion,
                'publishedVersion'    => $dto->publishedVersion,
                'definition'          => $dto->definition,
                'trigger'             => $dto->trigger,
                'availableEvents'     => $dto->availableEvents,
                'publishedAt'         => $dto->publishedAt?->toIso8601String(),
                'contentBaseLanguage' => $dto->contentBaseLanguage,
                'availableLanguages'  => $dto->availableLanguages,
                'availableFlows'      => $dto->availableFlows,
            ],
            'backUrl' => route('filament.assistant.resources.flows.index', ['tenant' => $dto->assistantId]),
        ]);
    }

    /**
     * Save the in-progress draft. Intentionally does **not** run the flow
     * validator — drafts may be empty or half-edited. Validation is an
     * explicit user action (`validate` endpoint) and runs atomically on
     * publish ({@see PublishFlowService}).
     *
     * The only failure modes here are structural: optimistic-lock conflict
     * (handled by SaveDraftService → 409) and unparseable trigger payload.
     * This lets authors create an empty flow and immediately attach it as
     * `default_flow_id` on an assistant before fleshing out the graph.
     */
    public function saveDraft(
        SaveDraftRequest $request,
        string $flow,
        SaveDraftService $service,
    ): JsonResponse {
        $definition      = $request->validated('definition');
        $definitionNodes = $this->extractDefinitionNodes($definition);
        $definitionEdges = $this->extractDefinitionEdges($definition);
        $triggerPayload  = $this->normalizeTriggerPayload($request->validated('trigger'));

        try {
            $newDraftVersion = $service->execute(
                flowId: $flow,
                nodes: $definitionNodes,
                edges: $definitionEdges,
                trigger: $triggerPayload,
                expectedDraftVersion: $request->validated('draft_version'),
            );
        } catch (InvalidTriggerPayloadException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors'  => [
                    [
                        'path'    => 'trigger.config',
                        'message' => $exception->getMessage(),
                    ],
                ],
            ], 422);
        }

        return response()->json(['draft_version' => $newDraftVersion]);
    }

    public function validate(
        ValidateFlowRequest $request,
        string $flow,
        ValidateFlowService $service,
    ): JsonResponse {
        $definition      = $request->validated('definition');
        $definitionNodes = $this->extractDefinitionNodes($definition);
        $definitionEdges = $this->extractDefinitionEdges($definition);
        $triggerPayload  = $this->normalizeTriggerPayload($request->validated('trigger'));

        $result = $service->execute(
            nodes: $definitionNodes,
            trigger: $triggerPayload,
            flowId: $flow,
            edges: $definitionEdges,
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

    /**
     * @param  array<string, mixed>  $definition
     *
     * @return array<int|string, mixed>
     */
    private function extractDefinitionNodes(array $definition): array
    {
        $nodes = $definition['nodes'] ?? $definition;

        return is_array($nodes) ? $nodes : [];
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @return array<int|string, mixed>
     */
    private function extractDefinitionEdges(array $definition): array
    {
        $edges = $definition['edges'] ?? [];

        return is_array($edges) ? $edges : [];
    }

    /**
     * @param  array<string, mixed>|null  $trigger
     *
     * @return array<string, mixed>|null
     */
    private function normalizeTriggerPayload(?array $trigger): ?array
    {
        if (null === $trigger || [] === $trigger) {
            return null;
        }

        if (true === ($trigger['_delete'] ?? false)) {
            return $trigger;
        }

        return isset($trigger['type']) ? $trigger : null;
    }
}
