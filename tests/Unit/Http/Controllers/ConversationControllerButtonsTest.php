<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Domains\Conversation\Models\ConversationMessage;
use App\Http\Controllers\Console\ConversationController;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The transcript shows what a contact could press from either stored format: the neutral `buttons` of new messages
 * and the raw provider `keyboard` of the ones stored before.
 */
final class ConversationControllerButtonsTest extends TestCase
{
    public function test_it_reads_the_labels_of_the_neutral_buttons(): void
    {
        $labels = $this->labels(['buttons' => [
            [['label' => 'Yes', 'action' => 'callback'], ['label' => 'No', 'action' => 'callback']],
            [['label' => 'Maybe', 'action' => 'callback']],
        ]]);

        $this->assertSame(['Yes', 'No', 'Maybe'], $labels);
    }

    public function test_it_reads_the_labels_of_a_legacy_keyboard_stored_as_rows(): void
    {
        $labels = $this->labels(['keyboard' => [[['text' => 'Yes', 'callback_data' => 'x']], [['text' => 'No', 'callback_data' => 'y']]]]);

        $this->assertSame(['Yes', 'No'], $labels);
    }

    public function test_the_neutral_buttons_win_over_a_legacy_keyboard(): void
    {
        $labels = $this->labels([
            'buttons'  => [[['label' => 'New', 'action' => 'reply']]],
            'keyboard' => [[['text' => 'Old']]],
        ]);

        $this->assertSame(['New'], $labels);
    }

    public function test_a_message_without_buttons_has_no_labels(): void
    {
        $this->assertSame([], $this->labels([]));
        $this->assertSame([], $this->labels(['buttons' => [[['label' => '', 'action' => 'reply']]]]));
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @return list<string>
     */
    private function labels(array $payload): array
    {
        $controller       = (new ReflectionClass(ConversationController::class))->newInstanceWithoutConstructor();
        $message          = new ConversationMessage();
        $message->payload = $payload;

        return (new ReflectionMethod($controller, 'buttons'))->invoke($controller, $message);
    }
}
