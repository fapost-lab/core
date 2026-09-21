<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use LogicException;
use Tests\TestCase;

final class NodeHandlerRegistryTest extends TestCase
{
    public function test_register_and_resolve_by_type_and_version(): void
    {
        $registry = $this->makeRegistry();
        $registry->register(TestConditionNodeHandlerV2::class);

        $resolved = $registry->resolve('condition', 2);

        $this->assertInstanceOf(TestConditionNodeHandlerV2::class, $resolved);
        $this->assertSame('condition', $resolved->type());
        $this->assertSame(2, $resolved->version());
    }

    public function test_register_fails_for_duplicate_type_version(): void
    {
        $registry = $this->makeRegistry();
        $registry->register(TestConditionNodeHandlerV2::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate handler registration: condition@2');

        $registry->register(TestConditionNodeHandlerV2::class);
    }

    public function test_register_fails_when_supported_versions_does_not_include_handler_version(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must include own version in supportedVersions()');

        $registry->register(InvalidSupportedVersionsHandler::class);
    }

    public function test_resolve_fails_when_handler_not_registered(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Handler not found: input@1');

        $registry->resolve('input', 1);
    }

    public function test_has_reports_registration_by_type_and_version(): void
    {
        $registry = $this->makeRegistry();
        $registry->register(TestConditionNodeHandlerV2::class);

        $this->assertTrue($registry->has('condition', 2));
        $this->assertFalse($registry->has('condition', 1));
        $this->assertFalse($registry->has('input', 2));
    }

    public function test_register_fails_after_registry_is_frozen(): void
    {
        $registry = $this->makeRegistry();
        $registry->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot register handlers after boot.');

        $registry->register(TestConditionNodeHandlerV2::class);
    }

    public function test_all_returns_latest_handler_version_per_type(): void
    {
        $registry = $this->makeRegistry();
        $registry->register(TestConditionNodeHandlerV1::class);
        $registry->register(TestConditionNodeHandlerV2::class);

        $all = $registry->all();

        $this->assertCount(1, $all);
        $this->assertSame('condition', $all[0]->type());
        $this->assertSame(2, $all[0]->version());

        // Like resolve(), all() builds fresh instances rather than handing out cached ones.
        $this->assertNotSame($all[0], $registry->all()[0]);
    }

    public function test_resolve_builds_a_new_instance_on_every_call(): void
    {
        $registry = $this->makeRegistry();
        $registry->register(TestConditionNodeHandlerV2::class);

        $first  = $registry->resolve('condition', 2);
        $second = $registry->resolve('condition', 2);

        $this->assertNotSame($first, $second);
    }

    private function makeRegistry(): NodeHandlerRegistry
    {
        return new NodeHandlerRegistry($this->app->make(NodeHandlerFactoryInterface::class));
    }
}

final class TestConditionNodeHandlerV1 implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'condition';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Condition';
    }

    public function category(): string
    {
        return 'Core';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
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

    public function label(): string
    {
        return 'Condition';
    }

    public function category(): string
    {
        return 'Core';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
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

    public function label(): string
    {
        return 'Condition';
    }

    public function category(): string
    {
        return 'Core';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}
