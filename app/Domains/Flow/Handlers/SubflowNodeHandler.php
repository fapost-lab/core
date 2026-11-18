<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers;

use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Exceptions\SubflowTargetInactiveException;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Subflow\SubflowMapping;
use App\Domains\Flow\Subflow\SubflowMappingValidator;
use App\Domains\Flow\Subflow\SubflowStarterService;
use Exception;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Fapost\Support\Builder\Schema\Fields\DurationField;
use Fapost\Support\Builder\Schema\Fields\FlowPickerField;
use Fapost\Support\Builder\Schema\Fields\KeyValueField;
use Fapost\Support\Builder\Schema\Schema;
use Fapost\Support\Builder\Schema\Section;

/**
 * Synchronous subflow invocation. Pauses the parent session and starts a
 * child session for the resolved {@code flow_id}; once the child reaches an
 * end node, {@see \App\Domains\Flow\Subflow\DefaultSubflowResumer} routes
 * the parent through the matching `success` / `cancelled` / `failed` handle.
 *
 * The optional `input_mapping` seeds the child's session variables from
 * expressions over the parent's state, evaluated here before the child starts;
 * the resumer applies `output_mapping` when the child hands back on `success`
 * ({@see SubflowMapping}).
 *
 * This handler is also responsible for being safe to retry: on idempotent
 * re-execution (engine retry, session re-loaded), if the parent already
 * has a running child it returns Waiting without spawning another.
 */
final class SubflowNodeHandler extends AbstractVersionedHandler implements NodeConfigValidatorInterface
{
    final public const string TYPE = 'subflow';

    public const string HANDLE_SUCCESS   = 'success';
    public const string HANDLE_CANCELLED = 'cancelled';
    public const string HANDLE_FAILED    = 'failed';

    private const string DEFAULT_TIMEOUT = 'PT24H';

    /** An input expression that is exactly one placeholder passes the value itself, not its text. */
    private const string SINGLE_PLACEHOLDER_PATTERN = '/^\s*\{\{\s*([\w.]+)\s*\}\}\s*$/';

    public function __construct(
        private readonly SubflowStarterService $starter,
        private readonly TemplateRenderer $templates,
        private readonly SubflowMappingValidator $mappingValidator,
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
                        DurationField::make('timeout')
                            ->label('Max wait time')
                            ->required()
                            ->default(self::DEFAULT_TIMEOUT)
                            ->units(['minutes', 'hours', 'days', 'weeks'])
                            ->help("If the child flow doesn't complete within this time, the parent routes to failed."),
                    ]),
            )
            ->section(
                Section::make('mapping', 'Inputs and outputs')
                    ->icon('adjustments-horizontal')
                    ->fields([
                        KeyValueField::make(SubflowMapping::INPUT)
                            ->label('Pass to the child flow')
                            ->keyLabel('Child variable')
                            ->valueLabel('Value')
                            ->placeholder(['user_id' => '{{flow.user_id}}'])
                            ->help(
                                'Sets temporary variables of the child flow when it starts. The value is text or an '
                                . 'expression over this flow, such as {{flow.user_id}}; a value that is only one '
                                . 'expression keeps its type.',
                            ),
                        KeyValueField::make(SubflowMapping::OUTPUT)
                            ->label('Take back from the child flow')
                            ->keyLabel('Variable here')
                            ->valueLabel('Child variable')
                            ->placeholder(['price' => 'price'])
                            ->help(
                                'Copies temporary variables of the child flow into this flow when the child ends with '
                                . 'success. A child variable that was never set arrives empty.',
                            ),
                    ]),
            )
            ->toArray();
    }

    /**
     * @param  array<string, mixed>  $node
     *
     * @return list<\Fapost\Foundation\Flow\DTO\NodeConfigIssue>
     */
    public function validate(array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        return $this->mappingValidator->validate($config);
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

        $input = $this->evaluateInput($config, $state, $context);

        try {
            $child = $this->starter->start($parent, $flowId, $duration, $context->nodeId, $input);
        } catch (SubflowTargetInactiveException $exception) {
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

    /**
     * The child's starting session variables, from `input_mapping` evaluated against the parent's state.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $state
     *
     * @return array<string, mixed>  child variable name => value
     */
    private function evaluateInput(array $config, array $state, NodeExecutionContext $context): array
    {
        $entries = SubflowMapping::entries($config, SubflowMapping::INPUT);

        if (null === $entries) {
            throw new InvalidNodeConfigException('subflow: input_mapping must map child variable names to expressions');
        }

        $input = [];

        foreach ($entries as $name => $expression) {
            $name = (string) $name;

            if (! SubflowMapping::isVariableName($name)) {
                throw new InvalidNodeConfigException("subflow: invalid input_mapping variable name '{$name}'");
            }

            if (! is_string($expression)) {
                throw new InvalidNodeConfigException("subflow: input_mapping value for '{$name}' must be a string");
            }

            $input[$name] = $this->evaluateExpression($expression, $state, $context);
        }

        return $input;
    }

    /**
     * A single placeholder is read as the value it names (an array stays an array, a number a number); anything
     * else is rendered as text.
     *
     * @param  array<string, mixed>  $state
     */
    private function evaluateExpression(string $expression, array $state, NodeExecutionContext $context): mixed
    {
        if (1 === preg_match(self::SINGLE_PLACEHOLDER_PATTERN, $expression, $match)) {
            return null !== $context->stateReader
                ? $context->stateReader->read($match[1])
                : data_get($state, $match[1]);
        }

        return $this->templates->render($expression, $context, $state);
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
