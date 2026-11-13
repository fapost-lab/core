<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution\Nodes;

use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use Tests\Fixtures\Solution\FeedbackCalls;
use Tests\Fixtures\Solution\FeedbackProbe;

/**
 * The `feedback.collect` node of the fixture Solution: it is grouped under the Solution's own palette group
 * (category = the Solution id), has the `default` and `error` exits, and writes the score it was configured with
 * into the Solution's own state namespace. It also validates its own config: a score outside 1-5 is an error, a
 * missing `label` a warning.
 */
final class FeedbackCollectNode extends AbstractVersionedHandler implements NodeConfigValidatorInterface
{
    final public const string TYPE = 'feedback.collect';

    public function __construct(
        private readonly FeedbackCalls $calls,
        public readonly FeedbackProbe $probe,
    ) {
        ++$this->calls->constructions;
    }

    public function version(): int
    {
        return 1;
    }

    public function label(): string
    {
        return 'Collect feedback';
    }

    public function category(): string
    {
        return 'feedback';
    }

    public function configSchema(): array
    {
        return ['type' => 'object', 'properties' => ['score' => ['type' => 'integer']]];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $score  = is_int($config['score'] ?? null) ? $config['score'] : 5;

        $this->calls->calls[] = ['action' => self::TYPE, 'tenant_id' => $context->tenantId, 'parameters' => ['score' => $score]];

        if ($score < 1 || $score > 5) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'error',
                errorMessage: 'score out of range',
            );
        }

        return NodeExecutionResult::executed('default', ['feedback.last_score' => $score]);
    }

    public function validate(array $node): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $issues = [];

        if (isset($config['score']) && (!is_int($config['score']) || $config['score'] < 1 || $config['score'] > 5)) {
            $issues[] = NodeConfigIssue::error('score_out_of_range', 'The score must be between 1 and 5.', 'score');
        }

        if (!isset($config['label'])) {
            $issues[] = NodeConfigIssue::warning('label_missing', 'The node has no label.', 'label');
        }

        return $issues;
    }
}
