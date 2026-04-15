<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Exceptions\FlowValidationException;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Validation\FlowDefinitionValidator;
use FAPost\Foundation\Contracts\NodeHandlerInterface;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use Tests\TestCase;

final class FlowDefinitionValidatorTest extends TestCase
{
    public function test_validate_accepts_connected_graph_with_single_entry_point(): void
    {
        $validator = $this->validator();

        $result = $validator->validate(
            nodes: [
                ['id' => 'n1', 'type' => 'condition', 'version' => 2, 'config' => []],
                ['id' => 'n2', 'type' => 'input', 'version' => 1, 'config' => []],
            ],
            edges: [
                ['id' => 'e1', 'source_node_id' => 'n1', 'target_node_id' => 'n2', 'transition' => 'default'],
            ],
        );

        $this->assertSame('n1', $result['entry_node_id']);
        $this->assertSame('n2', $result['adjacency']['n1']['default']);
    }

    public function test_validate_fails_when_node_version_is_missing(): void
    {
        $validator = $this->validator();

        $this->expectException(FlowValidationException::class);
        $this->expectExceptionMessage('must contain integer version');

        $validator->validate(
            nodes: [['id' => 'n1', 'type' => 'condition', 'config' => []]],
            edges: [],
        );
    }

    public function test_validate_fails_for_duplicate_transition_from_same_source(): void
    {
        $validator = $this->validator();

        $this->expectException(FlowValidationException::class);
        $this->expectExceptionMessage('Duplicate edge transition mapping');

        $validator->validate(
            nodes: [
                ['id' => 'n1', 'type' => 'condition', 'version' => 2, 'config' => []],
                ['id' => 'n2', 'type' => 'input', 'version' => 1, 'config' => []],
                ['id' => 'n3', 'type' => 'input', 'version' => 1, 'config' => []],
            ],
            edges: [
                ['id' => 'e1', 'source_node_id' => 'n1', 'target_node_id' => 'n2', 'transition' => 'default'],
                ['id' => 'e2', 'source_node_id' => 'n1', 'target_node_id' => 'n3', 'transition' => 'default'],
            ],
        );
    }

    public function test_validate_fails_when_graph_has_orphan_node(): void
    {
        $validator = $this->validator();

        $this->expectException(FlowValidationException::class);
        $this->expectExceptionMessage('orphan nodes');

        $validator->validate(
            nodes: [
                ['id' => 'n1', 'type' => 'condition', 'version' => 2, 'config' => []],
                ['id' => 'n2', 'type' => 'input', 'version' => 1, 'config' => []],
                ['id' => 'n3', 'type' => 'input', 'version' => 1, 'config' => []],
            ],
            edges: [
                ['id' => 'e1', 'source_node_id' => 'n1', 'target_node_id' => 'n2', 'transition' => 'default'],
                ['id' => 'e2', 'source_node_id' => 'n3', 'target_node_id' => 'n3', 'transition' => 'default'],
            ],
        );
    }

    public function test_validate_fails_when_required_transition_missing(): void
    {
        $validator = $this->validator();

        $this->expectException(FlowValidationException::class);
        $this->expectExceptionMessage('requires transition timeout');

        $validator->validate(
            nodes: [
                [
                    'id'                   => 'n1',
                    'type'                 => 'condition',
                    'version'              => 2,
                    'config'               => [],
                    'required_transitions' => ['timeout'],
                ],
                ['id' => 'n2', 'type' => 'input', 'version' => 1, 'config' => []],
            ],
            edges: [
                ['id' => 'e1', 'source_node_id' => 'n1', 'target_node_id' => 'n2', 'transition' => 'default'],
            ],
        );
    }

    public function test_validate_wraps_registry_resolution_error_as_flow_validation_exception(): void
    {
        $validator = $this->validator();

        $this->expectException(FlowValidationException::class);
        $this->expectExceptionMessage('references unknown handler version: condition@99');

        $validator->validate(
            nodes: [
                ['id' => 'n1', 'type' => 'condition', 'version' => 99, 'config' => []],
            ],
            edges: [],
        );
    }

    private function validator(): FlowDefinitionValidator
    {
        $registry = new NodeHandlerRegistry();
        $registry->register(new TestConditionHandlerV2());
        $registry->register(new TestInputHandlerV1());
        $registry->freeze();

        return new FlowDefinitionValidator($registry);
    }
}

final class TestConditionHandlerV2 implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'condition';
    }

    public function version(): int
    {
        return 2;
    }

    public function supportedVersions(): array
    {
        return [2];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class TestInputHandlerV1 implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'input';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::waiting();
    }
}
