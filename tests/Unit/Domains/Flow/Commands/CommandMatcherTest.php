<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Commands;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Commands\CommandMatcher;
use App\Domains\Flow\Commands\SolutionCommandRegistry;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use Tests\Fixtures\Solution\Commands\FeedbackCommand;
use Tests\Fixtures\Solution\Commands\FeedbackThankCommandType;
use Tests\TestCase;

final class CommandMatcherTest extends TestCase
{
    private SolutionAvailabilityInterface $availability;

    public function test_returns_null_for_non_slash_input(): void
    {
        $matcher = $this->app->make(CommandMatcher::class);
        $this->assertNull($matcher->match('hello', $this->assistant([])));
    }

    public function test_returns_builtin_reset_with_translation_key(): void
    {
        $matcher  = $this->app->make(CommandMatcher::class);
        $resolved = $matcher->match('/reset', $this->assistant([]));

        $this->assertNotNull($resolved);
        $this->assertSame('/reset', $resolved->command);
        $this->assertSame(CommandActionType::TerminateSession, $resolved->type);
        // Built-in commands carry a translation key — the literal text is
        // resolved by the executor through ContentTranslator, not by the
        // matcher itself.
        $this->assertNull($resolved->response);
        $this->assertSame('commands.reset.response', $resolved->responseKey);
        $this->assertSame('builtin', $resolved->origin);
    }

    public function test_tenant_command_overrides_builtin_response(): void
    {
        $matcher = $this->app->make(CommandMatcher::class);

        $resolved = $matcher->match('/reset', $this->assistant([
            ['command' => '/reset', 'type' => 'terminate_session', 'response' => 'Custom reset text'],
        ]));

        $this->assertNotNull($resolved);
        $this->assertSame('Custom reset text', $resolved->response);
        $this->assertSame('tenant', $resolved->origin);
    }

    public function test_tenant_only_command_resolves(): void
    {
        $matcher = $this->app->make(CommandMatcher::class);

        $resolved = $matcher->match('/menu', $this->assistant([
            ['command' => '/menu', 'type' => 'start_flow', 'flow_id' => 'flow-x', 'label' => 'Главное меню'],
        ]));

        $this->assertNotNull($resolved);
        $this->assertSame(CommandActionType::StartFlow, $resolved->type);
        $this->assertSame('flow-x', $resolved->flowId);
    }

    public function test_unknown_slash_command_returns_null(): void
    {
        $matcher = $this->app->make(CommandMatcher::class);
        $this->assertNull($matcher->match('/unknown', $this->assistant([])));
    }

    public function test_invalid_tenant_action_type_is_skipped(): void
    {
        $matcher = $this->app->make(CommandMatcher::class);

        $resolved = $matcher->match('/menu', $this->assistant([
            ['command' => '/menu', 'type' => 'bogus'],
        ]));

        $this->assertNull($resolved);
    }

    public function test_a_solution_command_resolves_to_its_registered_action_type_while_the_solution_is_available(): void
    {
        $this->registerFeedback(['feedback']);

        $resolved = $this->app->make(CommandMatcher::class)->match(' /feedback ', $this->assistant([]));

        $this->assertNotNull($resolved);
        $this->assertSame('/feedback', $resolved->command);
        $this->assertSame('solution', $resolved->origin);
        $this->assertNull($resolved->type);
        $this->assertSame('feedback.thank', $resolved->actionType);
        $this->assertSame(['text' => FeedbackCommand::REPLY], $resolved->config);
        $this->assertSame('feedback', $resolved->owner);
    }

    public function test_a_solution_command_falls_through_while_the_solution_is_not_available(): void
    {
        $this->registerFeedback([]);

        $this->assertNull($this->app->make(CommandMatcher::class)->match('/feedback', $this->assistant([])));
    }

    public function test_a_solution_command_whose_action_type_belongs_to_another_solution_is_skipped(): void
    {
        $this->registerFeedback(['feedback', 'survey'], commandOwner: 'survey');

        $this->assertNull($this->app->make(CommandMatcher::class)->match('/feedback', $this->assistant([])));
    }

    public function test_a_tenant_command_of_a_registered_type_resolves_while_the_solution_is_available_and_is_skipped_otherwise(): void
    {
        $assistant = $this->assistant([
            ['command' => '/thanks', 'type' => 'feedback.thank', 'config' => ['text' => 'x']],
        ]);

        $this->registerFeedback(['feedback']);
        $resolved = $this->app->make(CommandMatcher::class)->match('/thanks', $assistant);

        $this->assertNotNull($resolved);
        $this->assertSame('tenant', $resolved->origin);
        $this->assertNull($resolved->type);
        $this->assertSame('feedback.thank', $resolved->actionType);
        $this->assertSame(['text' => 'x'], $resolved->config);
        $this->assertSame('feedback', $resolved->owner);

        $this->availability->available = [];

        $this->assertNull($this->app->make(CommandMatcher::class)->match('/thanks', $assistant));
    }

    public function test_a_tenant_command_with_a_built_in_type_shadows_the_solution_command(): void
    {
        $this->registerFeedback(['feedback']);

        $resolved = $this->app->make(CommandMatcher::class)->match('/feedback', $this->assistant([
            ['command' => '/feedback', 'type' => 'send_message', 'text' => 'Own text'],
        ]));

        $this->assertNotNull($resolved);
        $this->assertSame('tenant', $resolved->origin);
        $this->assertSame(CommandActionType::SendMessage, $resolved->type);
        $this->assertSame('Own text', $resolved->text);
    }

    public function test_a_tenant_command_of_an_unavailable_registered_type_falls_through_to_the_next_layer(): void
    {
        $this->registerFeedback([]);

        $resolved = $this->app->make(CommandMatcher::class)->match('/feedback', $this->assistant([
            ['command' => '/feedback', 'type' => 'feedback.thank', 'config' => ['text' => 'x']],
        ]));

        $this->assertNull($resolved);
    }

    public function test_a_tenant_command_of_a_dotted_type_nobody_registers_is_skipped(): void
    {
        $this->registerFeedback(['feedback', 'hr']);

        $resolved = $this->app->make(CommandMatcher::class)->match('/vacation', $this->assistant([
            ['command' => '/vacation', 'type' => 'hr.vacation', 'config' => []],
        ]));

        $this->assertNull($resolved);
    }

    public function test_a_tenant_command_of_a_registered_type_without_a_config_object_gets_an_empty_config(): void
    {
        $this->registerFeedback(['feedback']);

        $resolved = $this->app->make(CommandMatcher::class)->match('/thanks', $this->assistant([
            ['command' => '/thanks', 'type' => 'feedback.thank', 'config' => 'broken'],
        ]));

        $this->assertNotNull($resolved);
        $this->assertSame([], $resolved->config);
    }

    /**
     * Registers the fixture Solution's action type and command and binds an availability port that answers for the
     * given Solution ids only.
     *
     * @param  list<string>  $available
     */
    private function registerFeedback(array $available, string $commandOwner = 'feedback'): void
    {
        $this->availability = new class () implements SolutionAvailabilityInterface {
            /** @var list<string> */
            public array $available = [];

            public function isAvailable(?string $solutionId): bool
            {
                return null === $solutionId || in_array($solutionId, $this->available, true);
            }

            public function isReady(string $solutionId): bool
            {
                return true;
            }
        };
        $this->availability->available = $available;
        $this->app->instance(SolutionAvailabilityInterface::class, $this->availability);

        $this->app->make(CommandActionTypeRegistry::class)->register(FeedbackThankCommandType::class, 'feedback');
        $this->app->make(SolutionCommandRegistry::class)->register(FeedbackCommand::class, $commandOwner);
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    private function assistant(array $commands): Assistant
    {
        $assistant           = new Assistant();
        $assistant->commands = $commands;

        return $assistant;
    }
}
