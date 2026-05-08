<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;

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

    public const string END_STATUS_SUCCESS   = 'success';
    public const string END_STATUS_CANCELLED = 'cancelled';
    public const string END_STATUS_FAILED    = 'failed';

    /** @var list<string> */
    public const array ALLOWED_END_STATUSES = [
        self::END_STATUS_SUCCESS,
        self::END_STATUS_CANCELLED,
        self::END_STATUS_FAILED,
    ];

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
        return [
            'status' => [
                'type'     => 'enum',
                'label'    => 'End status',
                'required' => true,
                'options'  => self::ALLOWED_END_STATUSES,
                'default'  => self::END_STATUS_SUCCESS,
            ],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $status = is_string($config['status'] ?? null) ? $config['status'] : self::END_STATUS_SUCCESS;

        if ( ! in_array($status, self::ALLOWED_END_STATUSES, true)) {
            throw new InvalidNodeConfigException(
                "end: invalid status '{$status}', expected one of: " . implode(', ', self::ALLOWED_END_STATUSES),
            );
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Finished,
            metadata: [self::END_STATUS_META => $status],
        );
    }
}
