<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Handlers\Abstract\AbstractVersionedHandler;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;

final class InputNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "input";

    private const string RECEIVED_META = "received";

    public function version(): int
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [
            'save_to' => [
                'type'        => 'state-picker',
                'label'       => 'Save to',
                'required'    => false,
                'placeholder' => 'flow.user_input',
            ],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $incomingText = $context->incoming?->text;

        if (null === $incomingText) {
            return new NodeExecutionResult(status: NodeExecutionStatus::Waiting);
        }

        $config    = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $saveToKey = is_string($config['save_to'] ?? null) ? $config['save_to'] : null;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: null !== $saveToKey ? [$saveToKey => $incomingText] : [],
            metadata: [self::RECEIVED_META => $incomingText],
        );
    }
}
