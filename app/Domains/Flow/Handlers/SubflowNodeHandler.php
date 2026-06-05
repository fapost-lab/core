<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Subflow\SubflowStarterService;
use Exception;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use FAPost\Foundation\DTO\NodeExecutionStatus;
use FAPost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use FAPost\Support\Builder\Schema\Fields\FlowPickerField;
use FAPost\Support\Builder\Schema\Fields\SelectField;
use FAPost\Support\Builder\Schema\Schema;
use FAPost\Support\Builder\Schema\Section;
use RuntimeException;

/**
 * Synchronous subflow invocation. Pauses the parent session and starts a
 * child session for the resolved {@code flow_id}; once the child reaches an
 * end node, {@see \App\Domains\Flow\Subflow\DefaultSubflowResumer} routes
 * the parent through the matching `success` / `cancelled` / `failed` handle.
 *
 * This handler is also responsible for being safe to retry: on idempotent
 * re-execution (engine retry, session re-loaded), if the parent already
 * has a running child it returns Waiting without spawning another.
 */
final class SubflowNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'subflow';

    public const string HANDLE_SUCCESS   = 'success';
    public const string HANDLE_CANCELLED = 'cancelled';
    public const string HANDLE_FAILED    = 'failed';

    private const string DEFAULT_TIMEOUT = 'PT24H';

    public function __construct(
        private readonly SubflowStarterService $starter,
        private readonly FlowSessionRepositoryInterface $sessions,
    ) {
    }

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
            ->required(['flow_id', 'timeout'])
            ->section(
                Section::make('target', 'Target flow')
                    ->icon('arrow-right-circle')
                    ->fields([
                        FlowPickerField::make('flow_id')
                            ->label('Flow')
                            ->required()
                            ->help(
                                'Runs as a child session; the parent pauses until it ends. '
                                . 'Resumes here via success / cancelled / failed once the child '
                                . 'reaches an end node (failed also covers timeout and inactive target).',
                            ),
                    ]),
            )
            ->section(
                Section::make('behavior', 'Behavior')
                    ->icon('clock')
                    ->fields([
                        SelectField::make('timeout')
                            ->label('Max wait time')
                            ->required()
                            ->default(self::DEFAULT_TIMEOUT)
                            ->options([
                                'PT1H'  => '1 hour',
                                'PT6H'  => '6 hours',
                                'PT12H' => '12 hours',
                                'PT24H' => '24 hours',
                                'PT48H' => '48 hours',
                                'P7D'   => '7 days',
                            ])
                            ->help("If the child flow doesn't complete within this time, the parent routes to failed."),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config  = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $flowId  = is_string($config['flow_id'] ?? null) ? mb_trim($config['flow_id']) : '';
        $timeout = is_string($config['timeout'] ?? null) && '' !== mb_trim($config['timeout'])
            ? mb_trim($config['timeout'])
            : self::DEFAULT_TIMEOUT;

        if ('' === $flowId) {
            throw new InvalidNodeConfigException('subflow: missing flow_id');
        }

        try {
            $duration = SubflowStarterService::parseTimeout($timeout);
        } catch (Exception $exception) {
            throw new InvalidNodeConfigException(
                "subflow: invalid timeout '{$timeout}' — must be ISO 8601 duration (e.g. PT24H).",
            );
        }

        $parent = FlowSession::query()->find($context->sessionId);

        if (! $parent instanceof FlowSession) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Failed,
                metadata: ['error' => 'parent session not found', 'error_type' => 'subflow_parent_missing'],
            );
        }

        // Idempotency: a child already in flight for this parent → just wait.
        if ($this->parentHasLiveChild($context->sessionId)) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Waiting,
                metadata: ['flow_id' => $flowId, 'reason' => 'child_already_running'],
            );
        }

        try {
            $child = $this->starter->start($parent, $flowId, $duration, $context->nodeId);
        } catch (RuntimeException $exception) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Failed,
                metadata: ['error' => $exception->getMessage(), 'error_type' => 'subflow_target_inactive'],
            );
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            metadata: [
                'flow_id'  => $flowId,
                'child_id' => (string)$child->getKey(),
                'timeout'  => $timeout,
            ],
        );
    }

    private function parentHasLiveChild(string $parentSessionId): bool
    {
        return FlowSession::query()
            ->where('parent_session_id', $parentSessionId)
            ->whereNotIn('status', [
                \App\Domains\Flow\Enums\FlowSessionStatus::Ended,
                \App\Domains\Flow\Enums\FlowSessionStatus::Completed,
                \App\Domains\Flow\Enums\FlowSessionStatus::Failed,
                \App\Domains\Flow\Enums\FlowSessionStatus::Cancelled,
                \App\Domains\Flow\Enums\FlowSessionStatus::Expired,
                \App\Domains\Flow\Enums\FlowSessionStatus::TerminatedByUser,
            ])
            ->exists();
    }
}
