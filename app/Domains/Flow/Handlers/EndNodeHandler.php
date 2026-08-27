<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\EnumCardsField;
use FAPost\Support\Builder\Schema\Schema;

/**
 * Explicit terminal node — marks the session ended with a discriminated status
 * (success / cancelled / failed). The engine and persister translate that into:
 *   • status = ended
 *   • end_status = <value>
 *   • current_node_id = null
 *
 * Subflow children additionally trigger parent resume on the matching handle
 * (success → success, cancelled → cancelled, failed → failed). The resume
 * delegate is invoked by the engine after this handler returns; see
 * {@see \App\Domains\Flow\Subflow\SubflowResumerInterface}.
 */
final class EndNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'end';

    public const string END_STATUS_META = 'end_status';

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
                EnumCardsField::make('status')
                    ->label('End status')
                    ->required()
                    ->default(EndStatus::Success)
                    ->options([
                        [
                            'value'  => EndStatus::Success->value,
                            'label'  => 'Success',
                            'icon'   => '✓',
                            'hint'   => 'Flow finished as expected',
                            'accent' => 'sage',
                        ],
                        [
                            'value'  => EndStatus::Cancelled->value,
                            'label'  => 'Cancelled',
                            'icon'   => '⊘',
                            'hint'   => 'User cancelled or session timed out',
                            'accent' => 'amber',
                        ],
                        [
                            'value'  => EndStatus::Failed->value,
                            'label'  => 'Failed',
                            'icon'   => '✕',
                            'hint'   => 'Flow ended due to error',
                            'accent' => 'rose',
                        ],
                    ]),
            ])
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config    = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $rawStatus = is_string($config['status'] ?? null) ? $config['status'] : EndStatus::Success->value;
        $status    = EndStatus::tryFrom($rawStatus);

        if (null === $status) {
            $allowed = implode(', ', array_column(EndStatus::cases(), 'value'));

            throw new InvalidNodeConfigException(
                "end: invalid status '{$rawStatus}', expected one of: {$allowed}",
            );
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Finished,
            metadata: [self::END_STATUS_META => $status->value],
        );
    }
}
