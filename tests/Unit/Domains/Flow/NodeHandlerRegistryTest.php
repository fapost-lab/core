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

    public function test_a_core_type_has_no_owner_and_a_solution_type_has_its_solution(): void
    {
        $registry = $this->makeRegistry();
        $registry->register(TestConditionNodeHandlerV2::class);
        $registry->register(AcmeThingNodeHandler::class, 'acme');

        $this->assertNull($registry->ownerOf('condition'));
        $this->assertSame('acme', $registry->ownerOf('acme.thing'));
        $this->assertNull($registry->ownerOf('acme.unknown'));
        $this->assertTrue($registry->has('acme.thing', 1));
    }

    public function test_a_core_type_may_not_contain_a_dot(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Core handler type "acme.thing" must not contain a dot');

        $registry->register(AcmeThingNodeHandler::class);
    }

    public function test_a_solution_type_must_start_with_the_solution_id(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must start with "other."');

        $registry->register(AcmeThingNodeHandler::class, 'other');
    }

    public function test_a_core_handler_must_return_a_node_category(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Handler "weird" returns category "Test" from category(); it must be a NodeCategory value.');

        $registry->register(WeirdCategoryNodeHandler::class);
    }

    public function test_a_core_handler_may_not_use_a_solution_id_as_category(): void
    {
        $registry = $this->makeRegistry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('returns category "acme"');

        $registry->register(CoreInAcmeGroupNodeHandler::class);
    }

    public function test_a_solution_handler_may_use_a_node_category_or_its_own_id_and_nothing_else(): void
    {
        $registry = $this->makeRegistry();
        $registry->register(AcmeThingNodeHandler::class, 'acme');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('it must be a NodeCategory value or the Solution id "acme"');

        $registry->register(AcmeWeirdNodeHandler::class, 'acme');
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
        return 'Logic';
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
        return 'Logic';
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
        return 'Logic';
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

final class AcmeThingNodeHandler extends \Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler
{
    final public const string TYPE = 'acme.thing';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'acme';
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class AcmeWeirdNodeHandler extends \Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler
{
    final public const string TYPE = 'acme.weird';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Stuff';
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class WeirdCategoryNodeHandler extends \Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler
{
    final public const string TYPE = 'weird';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Test';
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class CoreInAcmeGroupNodeHandler extends \Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler
{
    final public const string TYPE = 'plain';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'acme';
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}
