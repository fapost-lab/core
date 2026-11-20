<?php

declare(strict_types=1);

namespace App\Http\Controllers\Builder;

use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\DTOs\FlowValidationResultDto;
use App\Domains\Flow\Exceptions\InvalidTriggerPayloadException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Services\FlowTriggerSet;
use App\Domains\Flow\Services\LoadBuilderFlowService;
use App\Domains\Flow\Services\PublishFlowService;
use App\Domains\Flow\Services\SaveDraftService;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Flow\Templates\TemplateExpander;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RefuseWritesWhenTenantStopped;
use App\Http\Requests\SaveDraftRequest;
use App\Http\Requests\ValidateFlowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class BuilderFlowController extends Controller
{
    public function show(
        string $flow,
        FlowDraftRepositoryInterface $drafts,
        LoadBuilderFlowService $service,
        RefuseWritesWhenTenantStopped $writeGuard,
        Request $request,
    ): Response {
        Gate::authorize('view', $drafts->findByFlowId($flow));

        $dto = $service->execute(flowId: $flow);

        return Inertia::render('FlowBuilder/FlowEditor', [
            'flow' => [
                'flowId'              => $dto->flowId,
                'name'                => $dto->name,
                'draftVersion'        => $dto->draftVersion,
                'publishedVersion'    => $dto->publishedVersion,
                'definition'          => $dto->definition,
                'triggers'            => $dto->triggers,
                'availableEvents'     => $dto->availableEvents,
                'publishedAt'         => $dto->publishedAt?->toIso8601String(),
                'contentBaseLanguage' => $dto->contentBaseLanguage,
                'availableLanguages'  => $dto->availableLanguages,
                'availableFlows'      => $dto->availableFlows,
                'availableActions'    => $dto->availableActions,
                'availableCountries'  => $dto->availableCountries,
                'kind'                => $dto->kind,
                'availableTemplates'  => $dto->availableTemplates,
                'usedBy'              => $dto->usedBy,
                'webhookEndpoints'    => (object) $dto->webhookEndpoints,
                'readOnly'            => $writeGuard->refusesWritesFor($request->user()),
            ],
            'backUrl' => route('template' === $dto->kind ? 'console.flow-templates.index' : 'console.flows.index', ['assistant' => $dto->assistantId]),
        ]);
    }

    /**
     * Save the in-progress draft. Intentionally does **not** run the flow
     * validator — drafts may be empty or half-edited. Validation is an
     * explicit user action (`validate` endpoint) and runs atomically on
     * publish ({@see PublishFlowService}).
     *
     * The only failure modes here are structural: optimistic-lock conflict
     * (handled by SaveDraftService → 409) and an unparseable trigger list.
     * Saving never makes a trigger live: publishing does.
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
        $validated       = $request->validated();

        try {
            $newDraftVersion = $service->execute(
                flowId: $flow,
                nodes: $definitionNodes,
                edges: $definitionEdges,
                triggers: array_key_exists('triggers', $validated) ? $validated['triggers'] : null,
                expectedDraftVersion: $request->validated('draft_version'),
            );
        } catch (InvalidTriggerPayloadException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'errors'  => [
                    [
                        'path'    => 'triggers',
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
        FlowDraftRepositoryInterface $drafts,
        TemplateExpander $templates,
        FlowTriggerSet $triggerSet,
    ): JsonResponse {
        $validated       = $request->validated();
        $definition      = $request->validated('definition');
        $definitionNodes = $this->extractDefinitionNodes($definition);
        $definitionEdges = $this->extractDefinitionEdges($definition);
        $draft           = $drafts->findByFlowId($flow);

        // The list is checked as the builder sent it, item by item; only an absent one falls back to the draft's.
        $triggers = array_key_exists('triggers', $validated) && is_array($validated['triggers'])
            ? array_values($validated['triggers'])
            : $triggerSet->ofDraft($draft);

        $result = $service->execute(
            nodes: $definitionNodes,
            triggers: $triggers,
            flowId: $flow,
            edges: $definitionEdges,
            assistantId: $draft->assistant_id,
            engineId: $draft->expression_engine,
        );

        $templateErrors = $templates->draftErrors(
            $definitionNodes,
            $definitionEdges,
            $draft->assistant_id,
            $draft->isTemplate(),
            $draft->expression_engine,
        );

        if ([] !== $templateErrors) {
            $result = new FlowValidationResultDto(
                valid: false,
                errors: [...$result->errors, ...$templateErrors],
                warnings: $result->warnings,
            );
        }

        return response()->json($result);
    }

    public function publish(
        string $flow,
        FlowDraftRepositoryInterface $drafts,
        PublishFlowService $service,
    ): JsonResponse {
        Gate::authorize('publish', $drafts->findByFlowId($flow));

        // What the publish changes is checked on the locked draft, so a draft saved after a check never goes live
        // unchecked.
        $definition = $service->execute(
            flowId: $flow,
            authorize: static fn (FlowDraft $draft) => Gate::authorize('publishChanges', $draft),
        );

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
}
