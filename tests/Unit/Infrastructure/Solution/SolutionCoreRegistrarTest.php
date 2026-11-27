<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Commands\SolutionCommandRegistry;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use App\Domains\Flow\State\StateNamespaceRegistry;
use App\Domains\Solution\DTO\InstalledSolution;
use App\Infrastructure\Flow\ContainerActionHandlerFactory;
use App\Infrastructure\Flow\ContainerCommandExtensionFactory;
use App\Infrastructure\Flow\ContainerNodeHandlerFactory;
use App\Infrastructure\Flow\ContainerTriggerTypeFactory;
use App\Infrastructure\Solution\SolutionCoreRegistrar;
use App\Infrastructure\Solution\SolutionCoreRegistrarFactory;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Flow\Call\CallContext;
use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\Commands\CommandContext;
use Fapost\Foundation\Flow\Commands\CommandOutcome;
use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;
use Fapost\Foundation\Flow\Handlers\AbstractVersionedHandler;
use LogicException;
use Mockery;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;
use Tests\Fixtures\Solution\Actions\FeedbackScoreAction;
use Tests\Fixtures\Solution\Actions\FeedbackSubmitAction;
use Tests\Fixtures\Solution\Commands\FeedbackCommand;
use Tests\Fixtures\Solution\Commands\FeedbackThankCommandType;
use Tests\Fixtures\Solution\Nodes\FeedbackCollectNode;
use Tests\Fixtures\Solution\Triggers\FeedbackSubmittedTrigger;
use Tests\TestCase;

final class SolutionCoreRegistrarTest extends TestCase
{
    private ActionHandlerRegistry $registry;

    private NodeHandlerRegistry $nodes;

    private StateNamespaceRegistry $namespaces;

    private TriggerTypeRegistry $triggerTypes;

    private CommandActionTypeRegistry $commandActionTypes;

    private SolutionCommandRegistry $commands;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry           = new ActionHandlerRegistry(new ContainerActionHandlerFactory($this->app));
        $this->nodes              = new NodeHandlerRegistry(new ContainerNodeHandlerFactory($this->app));
        $this->namespaces         = new StateNamespaceRegistry();
        $this->triggerTypes       = new TriggerTypeRegistry(new ContainerTriggerTypeFactory($this->app));
        $this->commandActionTypes = new CommandActionTypeRegistry(new ContainerCommandExtensionFactory($this->app));
        $this->commands           = new SolutionCommandRegistry(new ContainerCommandExtensionFactory($this->app), new BuiltinCommandsRegistry());
        $this->logger             = new NullLogger();
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

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function sendMessageConfigs(): array
    {
        return [
            'missing text'            => [[], false],
            'empty string'            => [['text' => ''], false],
            'map of empty strings'    => [['text' => ['en' => '', 'ru' => '']], false],
            'empty map'               => [['text' => []], false],
            'string'                  => [['text' => 'Hello'], true],
            'map with one real value' => [['text' => ['en' => 'Hello', 'ru' => '']], true],
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

    public function test_a_declared_trigger_type_is_registered_under_the_solution(): void
    {
        $this->registrar()->registerTriggerType(FeedbackSubmittedTrigger::class);

        $this->assertSame(['feedback.submitted'], $this->triggerTypes->types());
        $this->assertSame('feedback', $this->triggerTypes->ownerOf('feedback.submitted'));
    }

    public function test_an_undeclared_trigger_type_names_the_solution_the_type_and_the_class(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Solution "hr" (acme/fapost-hr) registers trigger type "feedback.submitted" ('
            . FeedbackSubmittedTrigger::class . '), which extra.fapost.triggers does not declare.',
        );

        $this->registrar('hr')->registerTriggerType(FeedbackSubmittedTrigger::class);
    }

    public function test_a_class_that_is_not_a_trigger_type_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('which is not a trigger type');

        $this->registrar()->registerTriggerType(stdClass::class);
    }

    public function test_a_declared_command_action_type_is_registered_under_the_solution(): void
    {
        $this->registrar()->registerCommandActionType(FeedbackThankCommandType::class);

        $this->assertSame(['feedback.thank'], $this->commandActionTypes->types());
        $this->assertSame('feedback', $this->commandActionTypes->ownerOf('feedback.thank'));
    }

    public function test_an_undeclared_command_action_type_names_the_solution_the_type_and_the_class(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Solution "feedback" (acme/fapost-feedback) registers command action type "feedback.export" ('
            . UndeclaredExportCommandType::class . '), which extra.fapost.command_types does not declare.',
        );

        $this->registrar()->registerCommandActionType(UndeclaredExportCommandType::class);
    }

    public function test_a_command_action_type_of_another_solution_is_undeclared_here(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Solution "survey" (acme/fapost-survey) registers command action type "feedback.thank"');

        $this->registrar('survey')->registerCommandActionType(FeedbackThankCommandType::class);
    }

    public function test_a_class_that_is_not_a_command_action_type_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Solution "feedback" (acme/fapost-feedback) registers ' . stdClass::class . ', which is not a command action type');

        $this->registrar()->registerCommandActionType(stdClass::class);
    }

    public function test_a_declared_command_is_registered_under_the_solution(): void
    {
        $registrar = $this->registrar();
        $registrar->registerCommandActionType(FeedbackThankCommandType::class);
        $registrar->registerCommand(FeedbackCommand::class);

        $this->assertSame(['/feedback'], $this->commands->commands());
        $this->assertSame('feedback', $this->commands->ownerOf('/feedback'));
    }

    public function test_a_command_may_be_registered_before_its_own_action_type(): void
    {
        $registrar = $this->registrar();
        $registrar->registerCommand(FeedbackCommand::class);
        $registrar->registerCommandActionType(FeedbackThankCommandType::class);

        $this->assertTrue($this->commands->has('/feedback'));
        $this->assertTrue($this->commandActionTypes->has('feedback.thank'));
    }

    public function test_a_command_with_a_built_in_action_type_other_than_start_flow_is_accepted(): void
    {
        $this->registrar()->registerCommand(SendMessageCommand::class);

        $this->assertTrue($this->commands->has('/feedback'));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sendMessageConfigs')]
    public function test_a_send_message_command_needs_a_non_empty_text(array $config, bool $accepted): void
    {
        SendTextCommand::$config = $config;
        $registrar               = $this->registrar();

        if (! $accepted) {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('Solution "feedback" (acme/fapost-feedback) registers command "/feedback"');
        }

        $registrar->registerCommand(SendTextCommand::class);

        $this->assertTrue($this->commands->has('/feedback'));
    }

    public function test_an_undeclared_command_names_the_solution_the_command_and_the_class(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Solution "feedback" (acme/fapost-feedback) registers command "/export" ('
            . UndeclaredExportCommand::class . '), which extra.fapost.commands does not declare.',
        );

        $this->registrar()->registerCommand(UndeclaredExportCommand::class);
    }

    public function test_a_class_that_is_not_a_command_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Solution "feedback" (acme/fapost-feedback) registers ' . stdClass::class . ', which is not a chat command');

        $this->registrar()->registerCommand(stdClass::class);
    }

    public function test_a_command_that_starts_a_tenants_flow_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Solution "feedback" (acme/fapost-feedback) registers command "/feedback" (' . StartFlowCommand::class . ') with the action type "start_flow"');

        $this->registrar()->registerCommand(StartFlowCommand::class);
    }

    public function test_a_command_of_an_action_type_the_solution_does_not_declare_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('with the action type "feedback.export"');

        $this->registrar()->registerCommand(UndeclaredTypeCommand::class);
    }

    public function test_a_command_of_another_solutions_action_type_is_refused(): void
    {
        $this->registrar('survey')->registerCommandActionType(SurveyThankCommandType::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('with the action type "survey.thank"');

        $this->registrar()->registerCommand(ForeignTypeCommand::class);
    }

    public function test_a_command_whose_config_its_registered_action_type_refuses_is_refused_and_not_registered(): void
    {
        $registrar = $this->registrar();
        $registrar->registerCommandActionType(FeedbackThankCommandType::class);

        try {
            $registrar->registerCommand(EmptyConfigCommand::class);
            $this->fail('A command with a refused config was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Solution "feedback" (acme/fapost-feedback) registers command "/feedback"', $exception->getMessage());
            $this->assertStringContainsString('with a config "feedback.thank" refuses: The reply text is required.', $exception->getMessage());
        }

        $this->assertFalse($this->commands->has('/feedback'));
    }

    public function test_a_declared_command_that_core_took_as_built_in_is_skipped_and_logged(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('warning')
            ->once()
            ->with('solution.command.builtin_taken', Mockery::on(static fn (array $context): bool => 'feedback' === $context['solution'] && '/reset' === $context['command'] && ResetCommand::class === $context['class']));

        $this->registrar(commands: ['/reset'])->registerCommand(ResetCommand::class);

        $this->assertFalse($this->commands->has('/reset'));
        $this->assertSame([], $this->commands->commands());
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

    /**
     * @param  list<string>|null  $commands  what the manifest declares as chat commands; the Solution's own by default
     */
    private function registrar(string $id = 'feedback', bool $state = false, ?array $commands = null): SolutionCoreRegistrar
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
            triggers: [$id . '.submitted'],
            commandTypes: [$id . '.thank'],
            commands: $commands ?? ['/' . $id],
        );

        $registrar = (new SolutionCoreRegistrarFactory(
            $this->registry,
            $this->nodes,
            new ContainerNodeHandlerFactory($this->app),
            $this->namespaces,
            $this->triggerTypes,
            new ContainerTriggerTypeFactory($this->app),
            $this->commandActionTypes,
            $this->commands,
            new ContainerCommandExtensionFactory($this->app),
            new BuiltinCommandsRegistry(),
            $this->logger,
        ))->forSolution($solution);
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

abstract class FixtureCommandType implements CommandActionTypeInterface
{
    public function label(): string
    {
        return $this->type();
    }

    public function configSchema(): array
    {
        return [];
    }

    public function validateConfig(array $config): array
    {
        return [];
    }

    public function handle(CommandContext $context, array $config): CommandOutcome
    {
        return CommandOutcome::none();
    }
}

final class UndeclaredExportCommandType extends FixtureCommandType
{
    public function type(): string
    {
        return 'feedback.export';
    }
}

final class SurveyThankCommandType extends FixtureCommandType
{
    public function type(): string
    {
        return 'survey.thank';
    }
}

/**
 * @internal base of the commands below, which only differ in name, action type and config
 */
abstract class FixtureChatCommand implements SolutionCommandInterface
{
    public function command(): string
    {
        return '/feedback';
    }

    public function config(): array
    {
        return ['text' => 'Thanks'];
    }

    public function label(): string
    {
        return $this->command();
    }
}

final class SendMessageCommand extends FixtureChatCommand
{
    public function actionType(): string
    {
        return 'send_message';
    }
}

final class StartFlowCommand extends FixtureChatCommand
{
    public function actionType(): string
    {
        return 'start_flow';
    }
}

final class UndeclaredTypeCommand extends FixtureChatCommand
{
    public function actionType(): string
    {
        return 'feedback.export';
    }
}

final class ForeignTypeCommand extends FixtureChatCommand
{
    public function actionType(): string
    {
        return 'survey.thank';
    }
}

final class EmptyConfigCommand extends FixtureChatCommand
{
    public function actionType(): string
    {
        return 'feedback.thank';
    }

    public function config(): array
    {
        return [];
    }
}

final class UndeclaredExportCommand extends FixtureChatCommand
{
    public function command(): string
    {
        return '/export';
    }

    public function actionType(): string
    {
        return 'send_message';
    }
}

final class ResetCommand extends FixtureChatCommand
{
    public function command(): string
    {
        return '/reset';
    }

    public function actionType(): string
    {
        return 'send_message';
    }
}

final class SendTextCommand extends FixtureChatCommand
{
    /** @var array<string, mixed> */
    public static array $config = [];

    public function actionType(): string
    {
        return 'send_message';
    }

    public function config(): array
    {
        return self::$config;
    }
}
