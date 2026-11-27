<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Commands;

use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Validation\AssistantCommandsValidator;
use App\Domains\Flow\Validation\InvalidCommandConfigException;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use InvalidArgumentException;
use Tests\Fixtures\Solution\Commands\FeedbackThankCommandType;
use Tests\TestCase;

final class AssistantCommandsValidatorTest extends TestCase
{
    public function test_passes_for_valid_commands(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);

        $validator->validate([
            ['command' => '/menu', 'type' => 'start_flow', 'flow_id' => 'flow-1'],
            ['command' => '/help', 'type' => 'send_message', 'text' => 'help text'],
            ['command' => '/reset', 'type' => 'terminate_session', 'response' => 'Custom'],
        ]);

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_command_without_slash(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("commands[0].command must start with '/'");

        $validator->validate([['command' => 'menu', 'type' => 'send_message']]);
    }

    public function test_rejects_command_with_whitespace(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain whitespace');

        $validator->validate([['command' => '/main menu', 'type' => 'send_message']]);
    }

    public function test_rejects_duplicate_commands(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Duplicate command '/menu'");

        $validator->validate([
            ['command' => '/menu', 'type' => 'send_message', 'text' => 'a'],
            ['command' => '/menu', 'type' => 'send_message', 'text' => 'b'],
        ]);
    }

    public function test_rejects_unknown_action_type(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of');

        $validator->validate([['command' => '/x', 'type' => 'bogus']]);
    }

    public function test_rejects_start_flow_without_flow_id(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('flow_id is required when type=start_flow');

        $validator->validate([['command' => '/x', 'type' => 'start_flow']]);
    }

    public function test_rejects_builtin_override_with_different_type(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("overrides built-in '/reset'");

        $validator->validate([['command' => '/reset', 'type' => 'send_message', 'text' => 'custom']]);
    }

    public function test_allows_builtin_override_with_same_type(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);

        $validator->validate([['command' => '/reset', 'type' => 'terminate_session', 'response' => 'Custom']]);

        $this->expectNotToPerformAssertions();
    }

    public function test_accepts_a_registered_type_with_a_config_it_accepts(): void
    {
        $this->registerFeedbackType();

        $this->app->make(AssistantCommandsValidator::class)->validate([
            ['command' => '/thanks', 'type' => 'feedback.thank', 'config' => ['text' => 'Thank you']],
        ]);

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_a_registered_type_with_a_config_it_refuses_naming_the_field(): void
    {
        $this->registerFeedbackType();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('commands[0].config.text: The reply text is required.');

        $this->app->make(AssistantCommandsValidator::class)->validate([
            ['command' => '/thanks', 'type' => 'feedback.thank', 'config' => ['text' => '']],
        ]);
    }

    public function test_rejects_a_registered_type_without_a_config(): void
    {
        $this->registerFeedbackType();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('commands[0].config.text');

        $this->app->make(AssistantCommandsValidator::class)->validate([['command' => '/thanks', 'type' => 'feedback.thank']]);
    }

    public function test_rejects_a_registered_type_whose_config_is_not_an_object(): void
    {
        $this->registerFeedbackType();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('commands[0].config must be an object');

        $this->app->make(AssistantCommandsValidator::class)->validate([
            ['command' => '/thanks', 'type' => 'feedback.thank', 'config' => 'text'],
        ]);
    }

    public function test_rejects_a_dotted_type_nobody_registers_as_not_installed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('commands[0].type "hr.vacation" is not installed');

        $this->app->make(AssistantCommandsValidator::class)->validate([
            ['command' => '/vacation', 'type' => 'hr.vacation', 'config' => []],
        ]);
    }

    public function test_the_unknown_type_message_lists_the_registered_types(): void
    {
        $this->registerFeedbackType();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of: terminate_session, start_flow, send_message, feedback.thank');

        $this->app->make(AssistantCommandsValidator::class)->validate([['command' => '/x', 'type' => 'bogus']]);
    }

    public function test_keeps_an_entry_of_a_type_nobody_registers_any_more_when_it_is_stored_unchanged(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);
        $stored    = [
            ['config' => ['text' => 'a', 'lang' => 'en'], 'type' => 'hr.vacation', 'command' => '/vacation', 'label' => 'Vacation'],
        ];

        $validator->validate([
            ['command' => '/vacation', 'type' => 'hr.vacation', 'config' => ['text' => 'a', 'lang' => 'en']],
        ], $stored);

        $this->expectNotToPerformAssertions();
    }

    public function test_keeps_an_unchanged_stored_entry_whose_config_keys_come_back_in_another_order(): void
    {
        $validator = $this->app->make(AssistantCommandsValidator::class);
        $stored    = [
            ['command' => '/vacation', 'type' => 'hr.vacation', 'config' => ['lang' => 'en', 'text' => 'a']],
        ];

        $validator->validate([
            ['command' => '/vacation', 'type' => 'hr.vacation', 'config' => ['text' => 'a', 'lang' => 'en']],
        ], $stored);

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_a_stored_entry_of_a_type_nobody_registers_once_it_is_changed(): void
    {
        $stored = [['command' => '/vacation', 'type' => 'hr.vacation', 'config' => ['text' => 'a']]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not installed');

        $this->app->make(AssistantCommandsValidator::class)->validate([
            ['command' => '/vacation', 'type' => 'hr.vacation', 'config' => ['text' => 'changed']],
        ], $stored);
    }

    public function test_rejects_an_unregistered_type_when_only_another_command_is_stored_with_it(): void
    {
        $stored = [['command' => '/leave', 'type' => 'hr.vacation', 'config' => []]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not installed');

        $this->app->make(AssistantCommandsValidator::class)->validate([
            ['command' => '/vacation', 'type' => 'hr.vacation', 'config' => []],
        ], $stored);
    }

    public function test_a_stored_entry_does_not_excuse_an_undotted_unknown_type(): void
    {
        $stored = [['command' => '/x', 'type' => 'bogus']];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type must be one of');

        $this->app->make(AssistantCommandsValidator::class)->validate([['command' => '/x', 'type' => 'bogus']], $stored);
    }

    public function test_rejects_a_built_in_command_given_a_registered_type(): void
    {
        $this->registerFeedbackType();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("overrides built-in '/reset'");

        $this->app->make(AssistantCommandsValidator::class)->validate([
            ['command' => '/reset', 'type' => 'feedback.thank', 'config' => ['text' => 'x']],
        ]);
    }

    public function test_accepts_a_stored_entry_of_a_registered_type_whose_config_the_type_now_refuses(): void
    {
        $this->registerFeedbackType();
        $entry = ['command' => '/thanks', 'type' => 'feedback.thank', 'config' => ['text' => '']];

        $this->app->make(AssistantCommandsValidator::class)->validate([$entry], [$entry]);

        $this->expectNotToPerformAssertions();
    }

    public function test_rejects_a_changed_entry_of_a_registered_type_with_a_config_the_type_refuses(): void
    {
        $this->registerFeedbackType();
        $stored = [['command' => '/thanks', 'type' => 'feedback.thank', 'config' => ['text' => '']]];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('commands[0].config.text');

        $this->app->make(AssistantCommandsValidator::class)->validate([
            ['command' => '/thanks', 'type' => 'feedback.thank', 'config' => ['text' => '', 'other' => 1]],
        ], $stored);
    }

    public function test_a_refused_config_carries_the_entrys_key_and_its_errors_but_not_its_warnings(): void
    {
        $this->registerFeedbackType();

        try {
            $this->app->make(AssistantCommandsValidator::class)->validate([
                3 => ['command' => '/long', 'type' => 'feedback.thank', 'config' => ['text' => str_repeat('a', FeedbackThankCommandType::LONG_TEXT + 1)]],
                5 => ['command' => '/thanks', 'type' => 'feedback.thank', 'config' => ['text' => '']],
            ]);
            $this->fail('The empty reply text was accepted.');
        } catch (InvalidCommandConfigException $exception) {
            $this->assertSame(5, $exception->position);
            $this->assertSame('commands[5].config.text: The reply text is required.', $exception->getMessage());
            $this->assertSame(['missing_text'], array_map(static fn (NodeConfigIssue $issue): string => $issue->code, $exception->issues));
        }
    }

    private function registerFeedbackType(): void
    {
        $this->app->make(CommandActionTypeRegistry::class)->register(FeedbackThankCommandType::class, 'feedback');
    }
}
