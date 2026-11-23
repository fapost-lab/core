<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

final class BuilderFlowDto extends Data
{
    public function __construct(
        public readonly string $flowId,
        public readonly string $assistantId,
        public readonly string $name,
        public readonly int $draftVersion,
        public readonly ?int $publishedVersion,
        /** @var array<string, mixed> */
        public readonly array $definition,
        /**
         * The flow's triggers as the author edits them: the draft's list, or the live ones while the draft carries
         * none. Each entry: `{id, type, is_active, priority, config}`.
         *
         * @var list<array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}>
         */
        public readonly array $triggers,
        /** @var list<string> */
        public readonly array $availableEvents,
        public readonly ?Carbon $publishedAt,
        public readonly string $contentBaseLanguage,
        /** @var list<string> */
        public readonly array $availableLanguages,
        /**
         * Flows available for subflow selection — same assistant, never templates, ordered by name.
         * Each entry: `{id: string, name: string}`.
         *
         * @var list<array{id: string, name: string}>
         */
        public readonly array $availableFlows,
        /**
         * Action handler ids registered by active Solutions/Plugins — populate
         * the `call` node's handler-transport action picker. Empty until a
         * Solution provides one.
         *
         * @var list<string>
         */
        public readonly array $availableActions,
        /**
         * Countries the assistant serves — drive the phone input's format picker.
         * Each entry: `{value: ISO, label: "Name (+dial)"}`.
         *
         * @var list<array{value: string, label: string}>
         */
        public readonly array $availableCountries,
        /** `flow` or `template`: a template shows the output node and no trigger. */
        public readonly string $kind = 'flow',
        /**
         * Published templates of the same assistant, for the template node's picker. `outputs` are the handles of
         * the active version; the builder refreshes a placed node's snapshot from them when the flow opens.
         *
         * @var list<array{id: string, name: string, version: int, outputs: list<array{name: string, label: string|null}>, parameters: list<array{key: string, label: string|null, node_type: string, node_version: int, field: string, default: mixed}>}>
         */
        public readonly array $availableTemplates = [],
        /**
         * For a template: the flows that use it (published or in their draft), which publishing it republishes.
         *
         * @var list<array{id: string, name: string}>
         */
        public readonly array $usedBy = [],
        /**
         * The endpoints made for the flow's webhook triggers, keyed by trigger id: the URL and whether a secret is
         * set. The secret itself is never here.
         *
         * @var array<string, array{url: string, has_secret: bool, secret_rotated_at: string|null}>
         */
        public readonly array $webhookEndpoints = [],
        /**
         * The admission state of the flow's live triggers that have one, keyed by trigger id: paused by the run rate
         * breaker since when and why, and the runs refused with the last refusal.
         *
         * @var array<string, array{paused_at: string|null, paused_reason: string|null, refused_count: int, last_refused_at: string|null, last_refusal_reason: string|null}>
         */
        public readonly array $triggerStates = [],
    ) {
    }

    /**
     * @param  list<array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}>  $triggers
     * @param  list<string>                                $availableEvents
     * @param  list<string>                                $availableLanguages
     * @param  list<array{id: string, name: string}>       $availableFlows
     * @param  list<string>                                $availableActions
     * @param  list<array{value: string, label: string}>  $availableCountries
     * @param  list<array{id: string, name: string, version: int, outputs: list<array{name: string, label: string|null}>, parameters: list<array{key: string, label: string|null, node_type: string, node_version: int, field: string, default: mixed}>}>  $availableTemplates
     * @param  list<array{id: string, name: string}>      $usedBy
     * @param  array<string, array{url: string, has_secret: bool, secret_rotated_at: string|null}>  $webhookEndpoints
     * @param  array<string, array{paused_at: string|null, paused_reason: string|null, refused_count: int, last_refused_at: string|null, last_refusal_reason: string|null}>  $triggerStates
     */
    public static function fromDraftAndDefinition(
        FlowDraft $draft,
        ?FlowDefinition $published,
        array $triggers,
        array $availableEvents,
        string $contentBaseLanguage,
        array $availableLanguages,
        array $availableFlows = [],
        array $availableActions = [],
        array $availableCountries = [],
        array $availableTemplates = [],
        array $usedBy = [],
        array $webhookEndpoints = [],
        array $triggerStates = [],
    ): self {
        return new self(
            flowId: $draft->flow_id,
            assistantId: $draft->assistant_id,
            name: $draft->name,
            draftVersion: $draft->draft_version,
            publishedVersion: $published?->version,
            definition: [
                'nodes' => is_array($draft->nodes) ? $draft->nodes : [],
                'edges' => is_array($draft->edges) ? $draft->edges : [],
            ],
            triggers: $triggers,
            availableEvents: $availableEvents,
            publishedAt: $published?->published_at,
            contentBaseLanguage: $contentBaseLanguage,
            availableLanguages: $availableLanguages,
            availableFlows: $availableFlows,
            availableActions: $availableActions,
            availableCountries: $availableCountries,
            kind: $draft->kind->value,
            availableTemplates: $availableTemplates,
            usedBy: $usedBy,
            webhookEndpoints: $webhookEndpoints,
            triggerStates: $triggerStates,
        );
    }
}
