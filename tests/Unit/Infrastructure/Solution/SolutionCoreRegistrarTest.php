<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\State\StateNamespaceRegistry;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Infrastructure\Flow\ContainerActionHandlerFactory;
use App\Infrastructure\Flow\ContainerNodeHandlerFactory;
use App\Infrastructure\Solution\SolutionCoreRegistrar;
use App\Infrastructure\Solution\SolutionCoreRegistrarFactory;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use LogicException;
use stdClass;
use Tests\Fixtures\Solution\Actions\FeedbackScoreAction;
use Tests\Fixtures\Solution\Actions\FeedbackSubmitAction;
use Tests\Fixtures\Solution\Nodes\FeedbackCollectNode;
use Tests\TestCase;

final class SolutionCoreRegistrarTest extends TestCase
{
    private ActionHandlerRegistry $registry;

    private NodeHandlerRegistry $nodes;

    private StateNamespaceRegistry $namespaces;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry   = new ActionHandlerRegistry(new ContainerActionHandlerFactory($this->app));
        $this->nodes      = new NodeHandlerRegistry(new ContainerNodeHandlerFactory($this->app));
        $this->namespaces = new StateNamespaceRegistry();
    }

    /**
     * @return array<string, array{0: callable(SolutionCoreRegistrar): void}>
     */
    public static function unsupportedCalls(): array
    {
        return [
            'registerDataAccessor' => [static fn (SolutionCoreRegistrar $r) => $r->registerDataAccessor('hr', 'Acme\\Accessor')],
            'registerRagAdapter'   => [static fn (SolutionCoreRegistrar $r) => $r->registerRagAdapter('pgvector', 'Acme\\Adapter')],
            'registerRoutes'       => [static fn (SolutionCoreRegistrar $r) => $r->registerRoutes(static fn () => null)],
            'registerSchedule'     => [static fn (SolutionCoreRegistrar $r) => $r->registerSchedule(static fn () => null)],
            'registerMigrations'   => [static fn (SolutionCoreRegistrar $r) => $r->registerMigrations('/path')],
        ];
    }

    public function test_a_declared_action_is_registered_under_the_solution(): void
    {
        $registrar = $this->registrar();
        $registrar->registerActionHandler(FeedbackSubmitAction::class);
        $registrar->registerActionHandler(FeedbackScoreAction::class);

        $this->assertSame(['feedback.submit', 'feedback.score'], $this->registry->ids());
        $this->assertSame('feedback', $this->registry->ownerOf('feedback.submit'));
    }

    public function test_an_undeclared_action_names_the_solution_the_package_the_id_and_the_class(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Solution "feedback" (acme/fapost-feedback) registers action "feedback.export" ('
            . UndeclaredExportAction::class . '), which extra.fapost.actions does not declare.',
        );

        $this->registrar()->registerActionHandler(UndeclaredExportAction::class);
    }

    public function test_an_action_of_another_solution_is_undeclared_here(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not declare');

        $this->registrar('survey')->registerActionHandler(FeedbackSubmitAction::class);
    }

    public function test_a_duplicate_registration_names_both_classes(): void
    {
        $registrar = $this->registrar();
        $registrar->registerActionHandler(FeedbackSubmitAction::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Action handler 'feedback.submit' already registered");

        $registrar->registerActionHandler(FeedbackSubmitAction::class);
    }

    public function test_a_class_that_is_not_an_action_handler_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Solution "feedback" (acme/fapost-feedback) registers ' . stdClass::class . ', which is not an action handler');

        $this->registrar()->registerActionHandler(stdClass::class);
    }

    public function test_a_declared_node_type_is_registered_under_the_solution(): void
    {
        $this->registrar()->registerNodeHandler(FeedbackCollectNode::class);

        $this->assertTrue($this->nodes->has('feedback.collect', 1));
        $this->assertSame('feedback', $this->nodes->ownerOf('feedback.collect'));
        $this->assertInstanceOf(FeedbackCollectNode::class, $this->nodes->resolve('feedback.collect', 1));
    }

    public function test_a_node_type_in_a_core_palette_group_is_accepted(): void
    {
        $this->registrar()->registerNodeHandler(CoreGroupNode::class);

        $this->assertSame('feedback', $this->nodes->ownerOf('feedback.rate'));
    }

    public function test_two_versions_of_one_node_type_are_both_registered(): void
    {
        $registrar = $this->registrar();
        $registrar->registerNodeHandler(FeedbackCollectNode::class);
        $registrar->registerNodeHandler(FeedbackCollectNodeV2::class);

        $this->assertTrue($this->nodes->has('feedback.collect', 1));
        $this->assertTrue($this->nodes->has('feedback.collect', 2));
        $this->assertSame('feedback', $this->nodes->ownerOf('feedback.collect'));
    }

    public function test_an_undeclared_node_type_names_the_solution_the_package_the_type_and_the_class(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Solution "feedback" (acme/fapost-feedback) registers node type "feedback.export" ('
            . UndeclaredExportNode::class . '), which extra.fapost.nodes does not declare.',
        );

        $this->registrar()->registerNodeHandler(UndeclaredExportNode::class);
    }

    public function test_a_node_type_of_another_solution_is_undeclared_here(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not declare');

        $this->registrar('survey')->registerNodeHandler(FeedbackCollectNode::class);
    }

    public function test_a_class_that_is_not_a_node_handler_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Solution "feedback" (acme/fapost-feedback) registers ' . stdClass::class . ', which is not a node handler');

        $this->registrar()->registerNodeHandler(stdClass::class);
    }

    public function test_a_category_that_is_neither_a_core_group_nor_the_solution_id_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Solution "feedback" (acme/fapost-feedback) registers node type "feedback.stray" (' . StrayCategoryNode::class . ') with category "Stuff"',
        );

        $this->registrar()->registerNodeHandler(StrayCategoryNode::class);
    }

    public function test_the_id_of_another_solution_is_not_a_category(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('with category "survey"');

        $this->registrar()->registerNodeHandler(AnotherSolutionCategoryNode::class);
    }

    public function test_a_duplicate_node_registration_is_refused(): void
    {
        $registrar = $this->registrar();
        $registrar->registerNodeHandler(FeedbackCollectNode::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate handler registration: feedback.collect@1');

        $registrar->registerNodeHandler(FeedbackCollectNode::class);
    }

    public function test_the_state_namespace_is_registered_when_the_manifest_declares_it(): void
    {
        $this->registrar();
        $this->assertFalse($this->namespaces->has('feedback'));

        $this->registrar(state: true);

        $this->assertTrue($this->namespaces->has('feedback'));
        $this->assertSame('feedback', $this->namespaces->ownerOf('feedback'));
    }

    /**
     * @param  callable(SolutionCoreRegistrar): void  $call
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedCalls')]
    public function test_the_other_registrar_methods_are_not_supported_for_a_solution_yet(callable $call): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Solution "feedback" (acme/fapost-feedback) calls');

        $call($this->registrar());
    }

    private function registrar(string $id = 'feedback', bool $state = false): SolutionCoreRegistrar
    {
        $solution = new InstalledSolution(
            id: $id,
            name: ucfirst($id),
            description: null,
            package: 'acme/fapost-' . $id,
            version: '1.0.0',
            foundationConstraint: '^0.17',
            provider: 'Acme\\Provider',
            actions: [$id . '.submit', $id . '.score'],
            composerPath: null,
            nodes: [$id . '.collect', $id . '.rate', $id . '.stray'],
            state: $state,
        );

        $registrar = (new SolutionCoreRegistrarFactory($this->registry, $this->nodes, new ContainerNodeHandlerFactory($this->app), $this->namespaces))->forSolution($solution);
        $this->assertInstanceOf(SolutionCoreRegistrar::class, $registrar);

        return $registrar;
    }
}

final class UndeclaredExportAction implements ActionHandlerInterface
{
    public function id(): string
    {
        return 'feedback.export';
    }

    public function version(): int
    {
        return 1;
    }

    public function handle(array $parameters, CallContext $context): mixed
    {
        return null;
    }
}

/**
 * @internal base of the node handlers below, which only differ in type, version and category
 */
abstract class FixtureNode extends AbstractVersionedHandler
{
    public function version(): int
    {
        return 1;
    }

    public function execute(array $nodeConfig, array $state, \Fapost\Foundation\DTO\NodeExecutionContext $context): \Fapost\Foundation\DTO\NodeExecutionResult
    {
        return \Fapost\Foundation\DTO\NodeExecutionResult::executed();
    }
}

final class FeedbackCollectNodeV2 extends FixtureNode
{
    final public const string TYPE = 'feedback.collect';

    public function version(): int
    {
        return 2;
    }

    public function category(): string
    {
        return 'feedback';
    }
}

final class CoreGroupNode extends FixtureNode
{
    final public const string TYPE = 'feedback.rate';

    public function category(): string
    {
        return 'AI';
    }
}

final class UndeclaredExportNode extends FixtureNode
{
    final public const string TYPE = 'feedback.export';

    public function category(): string
    {
        return 'feedback';
    }
}

final class StrayCategoryNode extends FixtureNode
{
    final public const string TYPE = 'feedback.stray';

    public function category(): string
    {
        return 'Stuff';
    }
}

final class AnotherSolutionCategoryNode extends FixtureNode
{
    final public const string TYPE = 'feedback.stray';

    public function category(): string
    {
        return 'survey';
    }
}
