<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Flow\Enums\TagAction;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\ArrayField;
use FAPost\Support\Builder\Schema\Fields\SelectField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;

/**
 * `set_tag` node — dynamically tags the current contact from a flow.
 *
 * Tags are language-agnostic segmentation labels written to {@code contact_tags}
 * with {@code tagged_by} = the current flow session id. The node never branches:
 * its single `default` output continues the flow. All three actions are idempotent
 * so the handler is safe to retry under the session lock (see CLAUDE.md § Concurrency).
 */
final class SetTagNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'set_tag';

    public function __construct(
        private readonly ContactTagRepositoryInterface $tags,
        private readonly TemplateRenderer $templates,
    ) {
    }

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Data';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->required(['action'])
            ->section(
                Section::make('action', (string) __('builder.nodes.set_tag.section'))
                    ->icon('tag')
                    ->fields([
                        SelectField::make('action')
                            ->label((string) __('builder.nodes.set_tag.action'))
                            ->required()
                            ->default(TagAction::Add->value)
                            ->options(TagAction::options()),
                        ArrayField::make('tags')
                            ->label((string) __('builder.nodes.set_tag.tags'))
                            ->help((string) __('builder.nodes.set_tag.tags_help')),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];

        $action = TagAction::tryFrom(is_string($config['action'] ?? null) ? $config['action'] : '')
            ?? TagAction::Add;

        $tags = $this->resolveTags($config['tags'] ?? [], $state, $context);

        foreach ($tags as $tag) {
            match ($action) {
                TagAction::Add    => $this->tags->add($context->contactId, $tag, $context->sessionId),
                TagAction::Remove => $this->tags->remove($context->contactId, $tag),
                TagAction::Toggle => $this->tags->toggle($context->contactId, $tag, $context->sessionId),
            };
        }

        return NodeExecutionResult::executed(
            metadata: ['action' => $action->value, 'tags' => $tags],
        );
    }

    /**
     * Render each configured tag through the template engine and drop any that
     * resolve to empty/whitespace. Duplicate tags within a single node are
     * collapsed so the same label is not processed twice.
     *
     * @param  mixed                 $raw
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function resolveTags(mixed $raw, array $state, NodeExecutionContext $context): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $resolved = [];

        foreach ($raw as $candidate) {
            $value = $this->templates->render($candidate, $context, $state);

            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }

            $tag = mb_trim((string) $value);

            if ('' !== $tag) {
                $resolved[$tag] = true;
            }
        }

        return array_keys($resolved);
    }
}
