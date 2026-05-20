<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Enums\AssignTarget;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\Variables\Variable;
use App\Domains\Flow\State\Variables\VariableStorage;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Enums\StateNamespace;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\SelectField;
use FAPost\Support\Builder\Schema\Fields\TextareaField;
use FAPost\Support\Builder\Schema\Fields\TextField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;

final class AssignNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "assign";

    private const string TARGET_META = "target";
    private const string KEY_META    = "key";

    public function __construct(
        private readonly TemplateRenderer $templates,
        private readonly VariableResolverInterface $variableResolver,
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
            ->section(
                Section::make('legacy_assignment', 'Legacy assignment')
                    ->icon('arrow-path')
                    ->fields([
                        SelectField::make('target')
                            ->label('Target')
                            ->required()
                            ->options(AssignTarget::cases()),
                        TextField::make('key')
                            ->label('Key')
                            ->required()
                            ->placeholder('language'),
                        TextareaField::make('value')
                            ->label('Value')
                            ->required()
                            ->placeholder('{{flow.input}}'),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];

        if (is_array($config['operations'] ?? null)) {
            return $this->executeOperations($config['operations'], $state, $context);
        }

        return $this->executeLegacy($config, $state, $context);
    }

    /**
     * Execute the new multi-operation form. Each entry in {@code operations}
     * carries a fully-described variable plus its value template. All writes
     * are applied through the same resolver/writer combination so contact and
     * session variables share a single path-resolution code path.
     *
     * @param  array<int, mixed>     $operations
     * @param  array<string, mixed>  $state
     */
    private function executeOperations(array $operations, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $stateChanges = [];
        $writer       = $context->contactWriter;
        $appliedKeys  = [];

        foreach ($operations as $index => $operation) {
            if (! is_array($operation)) {
                throw new InvalidNodeConfigException("assign: operation #{$index} must be an object.");
            }

            $variableConfig = $operation['variable'] ?? null;

            if (! is_array($variableConfig)) {
                throw new InvalidNodeConfigException("assign: operation #{$index} missing variable definition.");
            }

            $variable = Variable::tryFromArray($variableConfig);

            if (! $variable instanceof Variable) {
                throw new InvalidNodeConfigException("assign: operation #{$index} has an incomplete variable definition.");
            }

            $value = $this->templates->render($operation['value'] ?? null, $context, $state);
            $path  = $this->variableResolver->resolveTargetPath($variable);

            if (VariableStorage::Contact === $variable->storage) {
                if (null === $writer) {
                    throw new InvalidNodeConfigException(
                        'assign: ContactWriter is unavailable in this NodeExecutionContext.',
                    );
                }

                $writer->write($path, $value);
                $appliedKeys[] = $path;

                continue;
            }

            $stateChanges[$path] = $value;
            $appliedKeys[]       = $path;
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: $stateChanges,
            metadata: ['paths' => $appliedKeys],
        );
    }

    /**
     * Legacy single-write form: {@code target=flow|contact, key=..., value=...}.
     *
     * Preserved verbatim so snapshots authored before the variable contract
     * migration keep executing identically. Once all definitions migrate this
     * branch can be removed in a follow-up.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     */
    private function executeLegacy(array $config, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $rawTarget = is_string($config['target'] ?? null) ? $config['target'] : null;
        $key       = is_string($config['key'] ?? null) ? $config['key'] : null;
        $target    = null !== $rawTarget ? AssignTarget::tryFrom($rawTarget) : null;

        if (null === $target || null === $key) {
            throw new InvalidNodeConfigException('assign: missing target or key');
        }

        $value = $this->templates->render($config['value'] ?? null, $context, $state);

        if (AssignTarget::Flow === $target) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
                stateChanges: [StateNamespace::Flow->value . ".{$key}" => $value],
                metadata: [self::TARGET_META => $target->value, self::KEY_META => $key],
            );
        }

        // 'contact' target: write through the immediate-write ContactWriter.
        // Engine wiring guarantees the writer is present at runtime; legacy unit
        // tests that build NodeExecutionContext by hand are required to inject it.
        $writer = $context->contactWriter;

        if (null === $writer) {
            throw new InvalidNodeConfigException(
                'assign: ContactWriter is unavailable in this NodeExecutionContext.',
            );
        }

        // ContactWriter expects fully-qualified `contact.*` paths. Pre-existing
        // shorthand 'language'/'contact.language' both alias to the canonical
        // language column; everything else is treated as a contact attribute.
        $path = ('language' === $key || 'contact.language' === $key)
            ? 'contact.language'
            : "contact.{$key}";

        $writer->write($path, $value);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            metadata: [self::TARGET_META => AssignTarget::Contact->value, self::KEY_META => $key],
        );
    }
}
