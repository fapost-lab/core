<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\TemplateResolver;
use App\Domains\Flow\State\FlowStateNamespace;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;

final class SetAttributeNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "set_attribute";

    private const string TARGET_META = "target";
    private const string KEY_META    = "key";

    public function __construct(
        private readonly TemplateResolver $templates,
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
            throw new InvalidNodeConfigException('set_attribute: missing target or key');
        }

        $value = $this->templates->resolve($config['value'] ?? null, $state);

        if ('flow' === $target) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
                stateChanges: [FlowStateNamespace::FLOW . ".{$key}" => $value],
                metadata: [self::TARGET_META => 'flow', self::KEY_META => $key],
            );
        }

        if ('contact' === $target && ('language' === $key || 'contact.language' === $key)) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
                effects: [
                    [
                        'type'  => 'set_contact_language',
                        'value' => (string)$value,
                    ],
                ],
                metadata: [self::TARGET_META => 'contact', self::KEY_META => $key],
            );
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            effects: [
                [
                    'type'  => 'set_contact_attribute',
                    'key'   => $key,
                    'value' => $value,
                ],
            ],
            metadata: [self::TARGET_META => 'contact', self::KEY_META => $key],
        );
    }
}
