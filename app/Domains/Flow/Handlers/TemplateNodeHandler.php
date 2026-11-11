<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Schema;

/**
 * The reference node of a flow template: in a host flow's draft it stands for the whole template, and its handles
 * are the template's named outputs. Publishing the host expands it ({@see \App\Domains\Flow\Templates\TemplateExpander}):
 * the node stays in the definition and its `enter` edge leads into a copy of the template's nodes, whose
 * `template_output` nodes carry the host's edges on. At runtime it is a pass-through that marks, in the session's
 * history and logs, where the copy was entered.
 *
 * Writes no state and has no side effect. `template@1` stays registered for good: published definitions keep it.
 */
final class TemplateNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'template';

    /** The handle from the reference node into the copy's entry node; only the published graph has it. */
    public const string HANDLE_ENTER = 'enter';

    /** Config key holding the template's flow id. */
    public const string CONFIG_TEMPLATE_FLOW_ID = 'template_flow_id';

    /** Config key holding the snapshot of the template's outputs the builder draws as handles. */
    public const string CONFIG_OUTPUTS = 'outputs';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Logic';
    }

    public function label(): string
    {
        return 'Template';
    }

    /**
     * The builder renders this node with its own panel (the template picker); the schema declares the one field
     * publishing requires.
     *
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->required([self::CONFIG_TEMPLATE_FLOW_ID])
            ->fields([
                TextField::make(self::CONFIG_TEMPLATE_FLOW_ID)
                    ->label('Template')
                    ->required(),
            ])
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed(self::HANDLE_ENTER);
    }
}
