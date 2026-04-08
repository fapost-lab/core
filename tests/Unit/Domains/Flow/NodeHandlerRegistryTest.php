<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Registry\NodeHandlerRegistry;
use FAPost\Foundation\Contracts\NodeHandlerInterface;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use LogicException;
use Tests\TestCase;

final class NodeHandlerRegistryTest extends TestCase
{
    public function test_register_and_resolve_by_type_and_version(): void
    {
        $registry = new NodeHandlerRegistry();
        $handler  = new TestConditionNodeHandlerV2();

        $registry->register($handler);

        $resolved = $registry->resolve('condition', 2);

        $this->assertSame($handler, $resolved);
    }

    public function test_register_fails_for_duplicate_type_version(): void
    {
        $registry = new NodeHandlerRegistry();
        $registry->register(new TestConditionNodeHandlerV2());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate handler registration: condition@2');

        $registry->register(new TestConditionNodeHandlerV2());
    }

    public function test_register_fails_when_supported_versions_does_not_include_handler_version(): void
    {
        $registry = new NodeHandlerRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must include own version in supportedVersions()');

        $registry->register(new InvalidSupportedVersionsHandler());
    }

    public function test_resolve_fails_when_handler_not_registered(): void
    {
        $registry = new NodeHandlerRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Handler not found: input@1');

        $registry->resolve('input', 1);
    }

    public function test_register_fails_after_registry_is_frozen(): void
    {
        $registry = new NodeHandlerRegistry();
        $registry->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot register handlers after boot.');

        $registry->register(new TestConditionNodeHandlerV2());
    }
}

final class TestConditionNodeHandlerV2 implements NodeHandlerInterface
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
        return [1, 2];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::completed();
    }
}

final class InvalidSupportedVersionsHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'condition';
    }

    public function version(): int
    {
        return 3;
    }

    public function supportedVersions(): array
    {
        return [1, 2];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::completed();
    }
}
