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
    ) {
    }

    /**
     * @param  list<string>  $availableEvents
     * @param  list<string>  $availableLanguages
     */
    public static function fromDraftAndDefinition(
        FlowDraft $draft,
        ?FlowDefinition $published,
        ?FlowTrigger $trigger,
        array $availableEvents,
        string $contentBaseLanguage,
        array $availableLanguages,
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
        );
    }
}
