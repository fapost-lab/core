<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Commands;

use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use InvalidArgumentException;
use Tests\TestCase;

final class AssistantCommandsValidatorTest extends TestCase
{
    public function test_passes_for_valid_commands(): void
    {
        $validator = new AssistantCommandsValidator(new BuiltinCommandsRegistry());

        $validator->validate([
            ['command' => '/menu', 'type' => 'start_flow', 'flow_id' => 'flow-1'],
            ['command' => '/help', 'type' => 'send_message', 'text' => 'help text'],
            ['command' => '/reset', 'type' => 'terminate_session', 'response' => 'Custom'],
        ]);

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_command_without_slash(): void
    {
        $validator = new AssistantCommandsValidator(new BuiltinCommandsRegistry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("commands[0].command must start with '/'");

        $validator->validate([['command' => 'menu', 'type' => 'send_message']]);
    }

    public function test_rejects_command_with_whitespace(): void
    {
        $validator = new AssistantCommandsValidator(new BuiltinCommandsRegistry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain whitespace');

        $validator->validate([['command' => '/main menu', 'type' => 'send_message']]);
    }

    public function test_rejects_duplicate_commands(): void
    {
        $validator = new AssistantCommandsValidator(new BuiltinCommandsRegistry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate command '/menu'");

        $validator->validate([
            ['command' => '/menu', 'type' => 'send_message', 'text' => 'a'],
            ['command' => '/menu', 'type' => 'send_message', 'text' => 'b'],
        ]);
    }

    public function test_rejects_unknown_action_type(): void
    {
        $validator = new AssistantCommandsValidator(new BuiltinCommandsRegistry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of');

        $validator->validate([['command' => '/x', 'type' => 'bogus']]);
    }

    public function test_rejects_start_flow_without_flow_id(): void
    {
        $validator = new AssistantCommandsValidator(new BuiltinCommandsRegistry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('flow_id is required when type=start_flow');

        $validator->validate([['command' => '/x', 'type' => 'start_flow']]);
    }

    public function test_rejects_builtin_override_with_different_type(): void
    {
        $validator = new AssistantCommandsValidator(new BuiltinCommandsRegistry());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("overrides built-in '/reset'");

        $validator->validate([['command' => '/reset', 'type' => 'send_message', 'text' => 'custom']]);
    }

    public function test_allows_builtin_override_with_same_type(): void
    {
        $validator = new AssistantCommandsValidator(new BuiltinCommandsRegistry());

        $validator->validate([['command' => '/reset', 'type' => 'terminate_session', 'response' => 'Custom']]);

        $this->expectNotToPerformAssertions();
    }
}
