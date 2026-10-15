<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Infrastructure\Flow\ContainerActionHandlerFactory;
use App\Infrastructure\Solution\SolutionCoreRegistrar;
use App\Infrastructure\Solution\SolutionCoreRegistrarFactory;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Flow\Call\CallContext;
use LogicException;
use stdClass;
use Tests\Fixtures\Solution\Actions\FeedbackScoreAction;
use Tests\Fixtures\Solution\Actions\FeedbackSubmitAction;
use Tests\TestCase;

final class SolutionCoreRegistrarTest extends TestCase
{
    private ActionHandlerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ActionHandlerRegistry(new ContainerActionHandlerFactory($this->app));
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

    public function test_a_solution_may_not_register_a_node_handler(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a Solution registers action handlers, not node types');

        $this->registrar()->registerNodeHandler('Acme\\Node');
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

    private function registrar(string $id = 'feedback'): SolutionCoreRegistrar
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
        );

        $registrar = (new SolutionCoreRegistrarFactory($this->registry))->forSolution($solution);
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
