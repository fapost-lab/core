<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Commands;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Commands\CommandMatcher;
use Tests\TestCase;

final class CommandMatcherTest extends TestCase
{
    public function test_returns_null_for_non_slash_input(): void
    {
        $matcher = new CommandMatcher(new BuiltinCommandsRegistry());
        $this->assertNull($matcher->match('hello', $this->assistant([])));
    }

    public function test_returns_builtin_reset_with_translation_key(): void
    {
        $matcher = new CommandMatcher(new BuiltinCommandsRegistry());
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
        $matcher = new CommandMatcher(new BuiltinCommandsRegistry());

        $resolved = $matcher->match('/reset', $this->assistant([
            ['command' => '/reset', 'type' => 'terminate_session', 'response' => 'Custom reset text'],
        ]));

        $this->assertNotNull($resolved);
        $this->assertSame('Custom reset text', $resolved->response);
        $this->assertSame('tenant', $resolved->origin);
    }

    public function test_tenant_only_command_resolves(): void
    {
        $matcher = new CommandMatcher(new BuiltinCommandsRegistry());

        $resolved = $matcher->match('/menu', $this->assistant([
            ['command' => '/menu', 'type' => 'start_flow', 'flow_id' => 'flow-x', 'label' => 'Главное меню'],
        ]));

        $this->assertNotNull($resolved);
        $this->assertSame(CommandActionType::StartFlow, $resolved->type);
        $this->assertSame('flow-x', $resolved->flowId);
    }

    public function test_unknown_slash_command_returns_null(): void
    {
        $matcher = new CommandMatcher(new BuiltinCommandsRegistry());
        $this->assertNull($matcher->match('/unknown', $this->assistant([])));
    }

    public function test_invalid_tenant_action_type_is_skipped(): void
    {
        $matcher = new CommandMatcher(new BuiltinCommandsRegistry());

        $resolved = $matcher->match('/menu', $this->assistant([
            ['command' => '/menu', 'type' => 'bogus'],
        ]));

        $this->assertNull($resolved);
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
