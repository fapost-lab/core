<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Services\FlowTriggerHint;
use Tests\TestCase;

/**
 * The one-line summary of a trigger shown under a flow's name in the console list.
 */
final class FlowTriggerHintTest extends TestCase
{
    public function test_a_message_trigger_lists_keywords_then_phrases(): void
    {
        $hint = FlowTriggerHint::for($this->trigger(FlowTriggerType::Message, [
            'keywords' => ['help', 'support'],
            'phrases'  => ['reset password'],
        ]));

        $this->assertSame(['type' => 'message', 'text' => 'help, support, reset password'], $hint);
    }

    public function test_a_message_trigger_counts_what_does_not_fit(): void
    {
        $hint = FlowTriggerHint::for($this->trigger(FlowTriggerType::Message, [
            'keywords' => ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
            'phrases'  => ['h'],
        ]));

        $this->assertSame('a, b, c, d, e, f +2', $hint['text'] ?? null);
    }

    public function test_a_long_message_summary_is_cut(): void
    {
        $hint = FlowTriggerHint::for($this->trigger(FlowTriggerType::Message, [
            'keywords' => [str_repeat('x', 50), str_repeat('y', 50)],
            'phrases'  => [],
        ]));

        $this->assertSame(81, mb_strlen($hint['text'] ?? ''));
        $this->assertStringEndsWith('…', $hint['text'] ?? '');
    }

    public function test_blank_keywords_and_phrases_are_skipped_and_an_empty_message_trigger_says_nothing(): void
    {
        $this->assertSame('real', FlowTriggerHint::for($this->trigger(FlowTriggerType::Message, ['keywords' => ['', '  ', 'real', 5], 'phrases' => 'nope']))['text'] ?? null);
        $this->assertNull(FlowTriggerHint::for($this->trigger(FlowTriggerType::Message, ['keywords' => [], 'phrases' => []])));
    }

    public function test_the_other_trigger_types_name_their_main_setting(): void
    {
        $this->assertSame(['type' => 'schedule', 'text' => '0 9 * * *'], FlowTriggerHint::for($this->trigger(FlowTriggerType::Schedule, ['cron' => ' 0 9 * * * '])));
        $this->assertSame(['type' => 'webhook', 'text' => 'POST /orders'], FlowTriggerHint::for($this->trigger(FlowTriggerType::Webhook, ['method' => 'POST', 'path' => '/orders'])));
        $this->assertSame(['type' => 'webhook', 'text' => '/orders'], FlowTriggerHint::for($this->trigger(FlowTriggerType::Webhook, ['path' => '/orders'])));
        $this->assertSame(['type' => 'event', 'text' => 'employee_registered'], FlowTriggerHint::for($this->trigger(FlowTriggerType::Event, ['event_name' => 'employee_registered'])));
        $this->assertSame(['type' => 'api', 'text' => 'start_onboarding'], FlowTriggerHint::for($this->trigger(FlowTriggerType::Api, ['route_key' => 'start_onboarding'])));
    }

    public function test_a_trigger_with_nothing_to_say_and_no_trigger_give_no_hint(): void
    {
        $this->assertNull(FlowTriggerHint::for(null));
        $this->assertNull(FlowTriggerHint::for($this->trigger(FlowTriggerType::Schedule, ['cron' => ''])));
        $this->assertNull(FlowTriggerHint::for($this->trigger(FlowTriggerType::Webhook, [])));
        $this->assertNull(FlowTriggerHint::for($this->trigger(FlowTriggerType::Event, ['event_name' => 5])));
        $this->assertNull(FlowTriggerHint::for($this->trigger(FlowTriggerType::Api, [])));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function trigger(FlowTriggerType $type, array $config): FlowTrigger
    {
        return (new FlowTrigger())->forceFill(['type' => $type, 'config' => $config]);
    }
}
