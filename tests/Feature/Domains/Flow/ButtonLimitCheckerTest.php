<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Flow\Validation\ButtonLimitChecker;
use Fapost\Foundation\Messaging\ButtonLimits;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;

final class ButtonLimitCheckerTest extends FeatureTestCase
{
    private Assistant $assistant;

    /** @var array<string, ChannelCapabilities> */
    private array $declared = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assistant = Assistant::factory()->create();
    }

    public function test_telegram_declares_the_inline_keyboard_limits(): void
    {
        $limits = $this->app->make(ChannelRegistryInterface::class)->capabilities('telegram')->buttonLimits;

        $this->assertSame(100, $limits->maxButtons);
        $this->assertSame(8, $limits->maxButtonsPerRow);
        $this->assertNull($limits->maxLabelLength);
    }

    public function test_too_many_buttons_in_one_message_give_a_warning(): void
    {
        $this->channel('plain', new ButtonLimits(maxButtons: 3));

        $warnings = $this->check([$this->keyboardNode($this->buttons(4, row: static fn (int $i): int => $i))]);

        $this->assertSame(
            ['Plain shows at most 3 buttons in one message; this node has 4.'],
            $this->messages($warnings),
        );
        $this->assertSame('nodes.n1', $warnings[0]->path);
        $this->assertSame(ButtonLimitChecker::CODE, $warnings[0]->code);
    }

    public function test_buttons_are_grouped_into_rows_and_the_longest_row_counts(): void
    {
        $this->channel('plain', new ButtonLimits(maxButtonsPerRow: 2));

        $buttons = $this->buttons(5, row: static fn (int $i): int => $i < 3 ? 1 : 2);

        $this->assertSame(
            ['Plain shows at most 2 buttons in a row; this node has a row of 3.'],
            $this->messages($this->check([$this->keyboardNode($buttons)])),
        );

        // Buttons without a `row` all share row 0.
        $this->assertSame(
            ['Plain shows at most 2 buttons in a row; this node has a row of 3.'],
            $this->messages($this->check([$this->keyboardNode($this->buttons(3))])),
        );
    }

    public function test_a_long_label_gives_a_warning_counted_in_characters(): void
    {
        $this->channel('plain', new ButtonLimits(maxLabelLength: 5));

        $node = $this->keyboardNode([['id' => 'b1', 'label' => 'Привіт']]);

        $this->assertSame(
            ['Plain shows at most 5 characters in a button label; the longest label here has 6.'],
            $this->messages($this->check([$node])),
        );

        $this->assertSame([], $this->check([$this->keyboardNode([['id' => 'b1', 'label' => 'Приві']])]));
        $this->assertSame([], $this->check([$this->keyboardNode([['id' => 'b1', 'label' => 'Ну ок']])]));
    }

    public function test_every_translation_of_a_label_is_checked(): void
    {
        $this->channel('plain', new ButtonLimits(maxLabelLength: 5));

        $node = $this->keyboardNode([['id' => 'b1', 'label' => ['en' => 'Yes', 'de' => 'Ja, bitte sehr']]]);

        $this->assertSame(
            ['Plain shows at most 5 characters in a button label; the longest label here has 14.'],
            $this->messages($this->check([$node])),
        );
    }

    public function test_a_templated_label_is_skipped_because_its_length_is_known_only_after_rendering(): void
    {
        $this->channel('plain', new ButtonLimits(maxLabelLength: 5));

        $node = $this->keyboardNode([
            ['id' => 'b1', 'label' => 'Order {{ flow.order_number }} please'],
            ['id' => 'b2', 'label' => ['en' => 'Hi {{flow.name}} there', 'de' => 'Hallo']],
        ]);

        $this->assertSame([], $this->check([$node]));
    }

    public function test_each_kind_of_limit_gives_its_own_warning(): void
    {
        $this->channel('plain', new ButtonLimits(maxButtons: 1, maxButtonsPerRow: 1, maxLabelLength: 1));

        $warnings = $this->check([$this->keyboardNode([
            ['id' => 'b1', 'label' => 'AA'],
            ['id' => 'b2', 'label' => 'B'],
        ])]);

        $this->assertCount(3, $warnings);
    }

    public function test_dynamic_buttons_check_only_the_row_size(): void
    {
        $this->channel('plain', new ButtonLimits(maxButtons: 1, maxButtonsPerRow: 3, maxLabelLength: 1));

        $node = $this->keyboardNode([['id' => 'b1', 'label' => 'Long label']], [
            'dynamic_buttons' => ['source' => 'flow.items', 'max_per_row' => 5],
        ]);

        $this->assertSame(
            ['Plain shows at most 3 buttons in a row; this node puts up to 5 dynamic buttons in a row.'],
            $this->messages($this->check([$node])),
        );

        $fits = $this->keyboardNode([], ['dynamic_buttons' => ['source' => 'flow.items', 'max_per_row' => 3]]);
        $this->assertSame([], $this->check([$fits]));

        // The default row size of dynamic buttons is 2.
        $default = $this->keyboardNode([], ['dynamic_buttons' => ['source' => 'flow.items']]);
        $this->assertSame([], $this->check([$default]));
    }

    public function test_select_and_confirm_inputs_are_checked_without_skipping_labels(): void
    {
        $this->channel('plain', new ButtonLimits(maxButtons: 2, maxButtonsPerRow: 2, maxLabelLength: 5));

        $config = [
            'buttons' => [
                ['id' => 'a', 'label' => 'Alpha {{x}}'],
                ['id' => 'b', 'label' => 'B'],
                ['id' => 'c', 'label' => 'C'],
            ],
        ];

        $warnings = $this->check([
            ['id' => 's', 'type' => 'input', 'config' => ['expected_type' => 'select', ...$config]],
            ['id' => 'c', 'type' => 'input', 'config' => ['expected_type' => 'confirm', ...$config]],
            ['id' => 't', 'type' => 'input', 'config' => ['expected_type' => 'text', ...$config]],
        ]);

        $this->assertCount(6, $warnings);
        $this->assertSame(['nodes.s', 'nodes.c'], array_values(array_unique(array_map(
            static fn (FlowValidationErrorDto $warning): string => $warning->path,
            $warnings,
        ))));
    }

    public function test_within_limits_give_no_warnings(): void
    {
        $this->channel('plain', new ButtonLimits(maxButtons: 4, maxButtonsPerRow: 4, maxLabelLength: 3));

        $this->assertSame([], $this->check([$this->keyboardNode($this->buttons(4, label: 'abc'))]));
    }

    public function test_undeclared_limits_are_not_checked(): void
    {
        $this->channel('free', new ButtonLimits());

        $this->assertSame([], $this->check([$this->keyboardNode($this->buttons(500, label: str_repeat('x', 500)))]));
    }

    public function test_channels_with_different_limits_are_named_per_limit_and_only_when_exceeded(): void
    {
        $this->channel('alpha', new ButtonLimits(maxButtonsPerRow: 8));
        $this->channel('beta', new ButtonLimits(maxButtonsPerRow: 3));
        $this->channel('gamma', new ButtonLimits(maxButtonsPerRow: 3));
        $this->channel('delta', new ButtonLimits(maxButtonsPerRow: 20));

        $this->assertSame(
            ['Beta, Gamma show at most 3 buttons in a row; this node has a row of 5.'],
            $this->messages($this->check([$this->keyboardNode($this->buttons(5))])),
        );

        $this->assertSame(
            ['Beta, Gamma show at most 3 buttons in a row; Alpha shows at most 8 buttons in a row; this node has a row of 10.'],
            $this->messages($this->check([$this->keyboardNode($this->buttons(10))])),
        );
    }

    public function test_an_inactive_channel_counts_and_an_assistant_without_channels_gives_no_warnings(): void
    {
        $this->assertSame([], $this->check([$this->keyboardNode($this->buttons(3))]));

        $this->channel('plain', new ButtonLimits(maxButtonsPerRow: 2), active: false);

        $this->assertCount(1, $this->check([$this->keyboardNode($this->buttons(3))]));
    }

    public function test_other_nodes_and_plain_messages_are_ignored(): void
    {
        $this->channel('plain', new ButtonLimits(maxButtons: 1, maxButtonsPerRow: 1, maxLabelLength: 1));

        $this->assertSame([], $this->check([
            ['id' => 'm', 'type' => 'send_message', 'config' => ['content_type' => 'text', 'text' => 'Hi', 'buttons' => $this->buttons(3)]],
            ['id' => 'x', 'type' => 'condition', 'config' => ['buttons' => $this->buttons(3)]],
        ]));
    }

    public function test_warnings_reach_the_validation_result_and_never_invalidate_the_flow(): void
    {
        $this->channel('plain', new ButtonLimits(maxButtonsPerRow: 1));

        $nodes = ['n1' => [
            'id'      => 'n1',
            'type'    => 'send_message',
            'version' => 1,
            'config'  => [
                'content_type'  => 'text_with_keyboard',
                'text'          => 'Pick',
                'keyboard_mode' => 'inline',
                'buttons'       => $this->buttons(2),
            ],
        ]];

        $service = $this->app->make(ValidateFlowService::class);

        $result = $service->execute($nodes, assistantId: (string) $this->assistant->getKey());

        $this->assertContains(ButtonLimitChecker::CODE, array_map(
            static fn (FlowValidationErrorDto $warning): string => $warning->code,
            $result->warnings,
        ));
        $this->assertSame([], $service->execute($nodes)->warnings);
    }

    /**
     * @param  list<FlowValidationErrorDto>  $warnings
     *
     * @return list<string>
     */
    private function messages(array $warnings): array
    {
        return array_map(static fn (FlowValidationErrorDto $warning): string => $warning->message, $warnings);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return list<FlowValidationErrorDto>
     */
    private function check(array $nodes): array
    {
        $map = [];

        foreach ($nodes as $node) {
            $map[$node['id']] = $node;
        }

        return $this->app->make(ButtonLimitChecker::class)->check($map, (string) $this->assistant->getKey());
    }

    /**
     * @param  (callable(int): int)|null  $row
     *
     * @return list<array<string, mixed>>
     */
    private function buttons(int $count, ?callable $row = null, string $label = 'B'): array
    {
        $buttons = [];

        for ($i = 0; $i < $count; $i++) {
            $buttons[] = ['id' => "b{$i}", 'label' => $label, ...(null === $row ? [] : ['row' => $row($i)])];
        }

        return $buttons;
    }

    /**
     * @param  list<array<string, mixed>>  $buttons
     * @param  array<string, mixed>        $config
     *
     * @return array<string, mixed>
     */
    private function keyboardNode(array $buttons, array $config = []): array
    {
        return [
            'id'     => 'n1',
            'type'   => 'send_message',
            'config' => [
                'content_type' => 'text_with_keyboard',
                'buttons'      => $buttons,
                ...$config,
            ],
        ];
    }

    private function channel(string $type, ButtonLimits $limits, bool $active = true): void
    {
        Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => $this->assistant->tenant_id,
            'type'         => $type,
            'is_active'    => $active,
        ]));

        $this->declared[$type] = new ChannelCapabilities([], $limits);

        $declared = $this->declared;

        $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($declared): void {
            $mock->shouldReceive('capabilities')->andReturnUsing(
                static fn (string $type): ChannelCapabilities => $declared[$type],
            );
            $mock->shouldReceive('label')->andReturnUsing(static fn (string $type): string => ucfirst($type));
        });
    }
}
