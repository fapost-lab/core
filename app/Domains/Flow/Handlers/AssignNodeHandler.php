<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\State\FlowStateNamespace;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;

final class AssignNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "assign";

    private const string TARGET_META = "target";
    private const string KEY_META    = "key";

    public function __construct(
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
        return [
            'target' => [
                'type'     => 'enum',
                'label'    => 'Target',
                'required' => true,
                'options'  => ['flow', 'contact'],
            ],
            'key' => [
                'type'        => 'string',
                'label'       => 'Key',
                'required'    => true,
                'placeholder' => 'language',
            ],
            'value' => [
                'type'        => 'text',
                'label'       => 'Value',
                'required'    => true,
                'placeholder' => '{{flow.input}}',
            ],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $target = is_string($config['target'] ?? null) ? $config['target'] : null;
        $key    = is_string($config['key'] ?? null) ? $config['key'] : null;

        if (null === $target || null === $key) {
            throw new InvalidNodeConfigException('assign: missing target or key');
        }

        $value = $this->templates->render($config['value'] ?? null, $context, $state);

        if ('flow' === $target) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
                stateChanges: [FlowStateNamespace::FLOW . ".{$key}" => $value],
                metadata: [self::TARGET_META => 'flow', self::KEY_META => $key],
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
            metadata: [self::TARGET_META => 'contact', self::KEY_META => $key],
        );
    }
}
