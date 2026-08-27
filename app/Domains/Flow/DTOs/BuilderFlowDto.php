<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
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
        /** @var array<string, mixed>|null */
        public readonly ?array $trigger,
        /** @var list<string> */
        public readonly array $availableEvents,
        public readonly ?Carbon $publishedAt,
        public readonly string $contentBaseLanguage,
        /** @var list<string> */
        public readonly array $availableLanguages,
        /**
         * Flows available for subflow selection — same assistant, ordered by name.
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
    ) {
    }

    /**
     * @param  list<string>                                $availableEvents
     * @param  list<string>                                $availableLanguages
     * @param  list<array{id: string, name: string}>       $availableFlows
     * @param  list<string>                                $availableActions
     * @param  list<array{value: string, label: string}>  $availableCountries
     */
    public static function fromDraftAndDefinition(
        FlowDraft $draft,
        ?FlowDefinition $published,
        ?FlowTrigger $trigger,
        array $availableEvents,
        string $contentBaseLanguage,
        array $availableLanguages,
        array $availableFlows = [],
        array $availableActions = [],
        array $availableCountries = [],
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
            trigger: null === $trigger
                ? null
                : [
                    'type'      => $trigger->type->value,
                    'is_active' => $trigger->is_active,
                    'priority'  => $trigger->priority,
                    'config'    => $trigger->config,
                ],
            availableEvents: $availableEvents,
            publishedAt: $published?->published_at,
            contentBaseLanguage: $contentBaseLanguage,
            availableLanguages: $availableLanguages,
            availableFlows: $availableFlows,
            availableActions: $availableActions,
            availableCountries: $availableCountries,
        );
    }
}
