<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\TextField;
use Fapost\Support\Builder\Schema\Schema;

/**
 * A named exit of a flow template. Inside the template it ends a branch; the outputs' names become the handles of
 * the template's reference node in a host flow. Publishing the host joins each copy of an output to whatever the
 * host connected to that handle, so at runtime the node is a pass-through on `default`. Several output nodes may
 * share a name: they are one exit. An output with nothing connected behind it ends the session, like any node
 * without an outgoing edge.
 *
 * Writes no state and has no side effect. `template_output@1` stays registered for good: published definitions keep it.
 */
final class TemplateOutputNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'template_output';

    /** Config key holding the exit's name: the handle on the reference node. */
    public const string CONFIG_NAME = 'name';

    /** Config key holding the exit's label shown in the builder. */
    public const string CONFIG_LABEL = 'label';

    /** What an exit's name may look like: a lowercase key, safe as an edge handle. */
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

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
        return 'Template output';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->required([self::CONFIG_NAME])
            ->fields([
                TextField::make(self::CONFIG_NAME)
                    ->label('Output name')
                    ->required()
                    ->placeholder('returned')
                    ->regex('^[a-z][a-z0-9_]{0,63}$', 'Lowercase latin letters, digits and _, starting with a letter.')
                    ->help('The handle this exit becomes on the template node in a flow. Outputs with the same name are one exit.'),
                TextField::make(self::CONFIG_LABEL)
                    ->label('Label')
                    ->placeholder('Operator returned'),
            ])
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}
