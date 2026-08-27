<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\State\SystemStateKeys;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\NumberField;
use Fapost\Support\Builder\Schema\Schema;

final class DelayNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = "delay";

    private const string RESUME_AT_META = "resume_at";
    private const string SECONDS_META   = "seconds";

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Logic';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->fields([
                NumberField::make('seconds')
                    ->label('Delay (seconds)')
                    ->default(60),
            ])
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $alreadyScheduled = data_get($state, SystemStateKeys::DELAY_NODE_PREFIX . ".{$context->nodeId}.scheduled_at");

        if (null !== $alreadyScheduled) {
            return new NodeExecutionResult(status: NodeExecutionStatus::Waiting);
        }

        $config   = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $seconds  = (int)($config['seconds'] ?? 60);
        $now      = now();
        $resumeAt = $now->clone()->addSeconds($seconds);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            stateChanges: [
                SystemStateKeys::DELAY_NODE_PREFIX . ".{$context->nodeId}.resume_at"    => $resumeAt->toIso8601String(),
                SystemStateKeys::DELAY_NODE_PREFIX . ".{$context->nodeId}.scheduled_at" => $now->toIso8601String(),
            ],
            metadata: [
                self::RESUME_AT_META => $resumeAt->toIso8601String(),
                self::SECONDS_META   => $seconds,
            ],
        );
    }
}
