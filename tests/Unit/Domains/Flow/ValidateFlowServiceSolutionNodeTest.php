<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Fapost\Foundation\Flow\Contracts\NodeConfigValidatorInterface;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use RuntimeException;
use Tests\Fixtures\Solution\Nodes\FeedbackCollectNode;
use Tests\TestCase;

/**
 * The validation of a Solution's nodes: its handler's own checks (errors block publishing, warnings do not) and the
 * warning for an `error` exit that leads nowhere.
 */
final class ValidateFlowServiceSolutionNodeTest extends TestCase
{
    public function test_an_error_issue_of_the_handler_blocks_publishing_and_points_at_the_field(): void
    {
        $result = $this->service()->execute(
            ['c' => $this->node(['score' => 9, 'label' => 'x'])],
            edges: $this->errorEdge(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame(['score_out_of_range'], array_column($result->errors, 'code'));
        $this->assertSame('nodes.c.config.score', $result->errors[0]->path);
        $this->assertSame('The score must be between 1 and 5.', $result->errors[0]->message);
        $this->assertSame([], $result->warnings);
    }

    public function test_a_warning_issue_of_the_handler_does_not_block_publishing(): void
    {
        $result = $this->service()->execute(
            ['c' => $this->node(['score' => 3])],
            edges: $this->errorEdge(),
        );

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->errors);
        $this->assertSame(['label_missing'], array_column($result->warnings, 'code'));
        $this->assertSame('nodes.c.config.label', $result->warnings[0]->path);
    }

    public function test_a_node_that_passes_its_own_checks_is_valid_without_warnings(): void
    {
        $result = $this->service()->execute(
            ['c' => $this->node(['score' => 3, 'label' => 'x'])],
            edges: $this->errorEdge(),
        );

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->warnings);
    }

    public function test_a_solution_node_without_a_connected_error_exit_gets_a_warning_that_does_not_block(): void
    {
        $result = $this->service()->execute(
            ['c' => $this->node(['score' => 3, 'label' => 'x']), 'e' => ['id' => 'e', 'type' => 'feedback.collect', 'version' => 1, 'config' => ['label' => 'y']]],
            edges: [['from' => 'c', 'to' => 'e', 'handle' => 'default']],
        );

        $this->assertTrue($result->valid);
        $this->assertSame(['solution_node_error_unhandled', 'solution_node_error_unhandled'], array_column($result->warnings, 'code'));
        $this->assertSame(['nodes.c', 'nodes.e'], array_column($result->warnings, 'path'));
    }

    public function test_publishing_does_not_ask_whether_the_solution_is_active(): void
    {
        // The service has no availability port at all: a registered type publishes whatever the tenant has switched on.
        $result = $this->service()->execute(
            ['c' => $this->node(['score' => 3, 'label' => 'x'])],
            edges: $this->errorEdge(),
        );

        $this->assertTrue($result->valid);
    }

    public function test_a_numeric_string_version_still_reaches_the_handler_validator(): void
    {
        $node            = $this->node(['score' => 9, 'label' => 'x']);
        $node['version'] = '1';

        $result = $this->service()->execute(['c' => $node], edges: $this->errorEdge());

        $this->assertContains('score_out_of_range', array_column($result->errors, 'code'));
    }

    public function test_a_validator_that_throws_is_reported_as_an_error_not_a_crash(): void
    {
        $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
        $registry->register(ThrowingValidatorNode::class, 'broken');

        $result = $this->service($registry)->execute(
            ['c' => ['id' => 'c', 'type' => 'broken.check', 'version' => 1, 'config' => []]],
            edges: $this->errorEdge(),
        );

        $this->assertFalse($result->valid);
        $this->assertSame(['node_validator_failed'], array_column($result->errors, 'code'));
        $this->assertSame('nodes.c', $result->errors[0]->path);
        $this->assertStringContainsString('broken.check', $result->errors[0]->message);
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function node(array $config): array
    {
        return ['id' => 'c', 'type' => 'feedback.collect', 'version' => 1, 'config' => $config];
    }

    /**
     * @return list<array<string, string>>
     */
    private function errorEdge(): array
    {
        return [['from' => 'c', 'to' => 'x', 'handle' => 'error']];
    }

    private function service(?NodeHandlerRegistry $registry = null): ValidateFlowService
    {
        if (null === $registry) {
            $registry = new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
            $registry->register(FeedbackCollectNode::class, 'feedback');
        }

        return new ValidateFlowService(
            registry: $registry,
            dataAccessors: $this->createStub(DataAccessorRegistryInterface::class),
            triggerValidator: $this->createStub(FlowTriggerConfigValidatorInterface::class),
            tenantEvents: $this->createStub(TenantEventRepositoryInterface::class),
            tenantContext: $this->createStub(TenantContextInterface::class),
        );
    }
}

final class ThrowingValidatorNode extends AbstractVersionedHandler implements NodeConfigValidatorInterface
{
    final public const string TYPE = 'broken.check';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'broken';
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed('default');
    }

    public function validate(array $node): array
    {
        throw new RuntimeException('boom');
    }
}
