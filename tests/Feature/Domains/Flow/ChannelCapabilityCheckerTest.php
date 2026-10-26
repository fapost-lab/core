<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Flow\Validation\ChannelCapabilityChecker;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Fapost\Foundation\Messaging\ChannelCapability;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;

final class ChannelCapabilityCheckerTest extends FeatureTestCase
{
    private Assistant $assistant;

    /** @var array<string, ChannelCapabilities>|null */
    private ?array $declared = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assistant = Assistant::factory()->create();
    }

    public function test_an_inline_keyboard_that_removes_buttons_needs_callback_and_remove_buttons(): void
    {
        $this->channel('plain');

        $warnings = $this->check([$this->keyboardNode('inline')]);

        $this->assertSame(
            [
                'Plain cannot show buttons under a message that send a press back to the flow.',
                'Plain cannot remove buttons from a sent message: they stay until the next message.',
            ],
            array_map(static fn ($warning): string => $warning->message, $warnings),
        );
        $this->assertSame('nodes.n1', $warnings[0]->path);
        $this->assertSame(ChannelCapabilityChecker::CODE, $warnings[0]->code);
    }

    public function test_keyboard_mode_defaults_to_inline_and_buttons_persist_when_not_removed(): void
    {
        $this->channel('plain', [ChannelCapability::CallbackButtons]);

        $node = $this->keyboardNode(null, ['remove_keyboard_after_press' => false]);

        $warnings = $this->check([$node]);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('keep the buttons of older messages pressable', $warnings[0]->message);
    }

    public function test_reply_buttons_are_checked_per_special_kind(): void
    {
        $this->channel('plain');

        $warnings = $this->check([$this->keyboardNode('reply', [
            'buttons' => [
                ['id' => 'b1', 'label' => 'A'],
                ['id' => 'b2', 'label' => 'B'],
                ['id' => 'b3', 'label' => 'Phone', 'special' => 'request_contact'],
                ['id' => 'b4', 'label' => 'Where', 'special' => 'request_location'],
            ],
        ])]);

        $this->assertSame(
            [
                'Plain cannot show reply buttons.',
                'Plain cannot ask the user to share a contact.',
                'Plain cannot ask the user to share a location.',
            ],
            array_map(static fn ($warning): string => $warning->message, $warnings),
        );
    }

    public function test_input_nodes_that_expect_a_contact_or_location_need_the_share_buttons(): void
    {
        $this->channel('plain');

        $warnings = $this->check([
            ['id' => 'c', 'type' => 'input', 'config' => ['expected_type' => 'contact']],
            ['id' => 'l', 'type' => 'input', 'config' => ['expected_type' => 'location']],
            ['id' => 't', 'type' => 'input', 'config' => ['expected_type' => 'text']],
        ]);

        $this->assertSame(['nodes.c', 'nodes.l'], array_map(static fn ($warning): string => $warning->path, $warnings));
    }

    public function test_select_and_confirm_inputs_need_callback_buttons_only(): void
    {
        $this->channel('plain', [ChannelCapability::ReplyButtons]);

        $warnings = $this->check([
            ['id' => 's', 'type' => 'input', 'config' => ['expected_type' => 'select']],
            ['id' => 'c', 'type' => 'input', 'config' => ['expected_type' => 'confirm']],
        ]);

        $this->assertSame(['nodes.s', 'nodes.c'], array_map(static fn ($warning): string => $warning->path, $warnings));
        $this->assertCount(2, $warnings);

        $this->channel('plain', [ChannelCapability::CallbackButtons]);
        $this->assertSame([], $this->check([
            ['id' => 's', 'type' => 'input', 'config' => ['expected_type' => 'select']],
        ]));
    }

    public function test_dynamic_buttons_count_as_buttons(): void
    {
        $this->channel('plain');

        $dynamic = ['dynamic_buttons' => ['source' => 'flow.items'], 'buttons' => []];

        $inline = $this->check([$this->keyboardNode('inline', $dynamic)]);
        $this->assertCount(2, $inline);

        $reply = $this->check([$this->keyboardNode('reply', $dynamic)]);
        $this->assertSame(['Plain cannot show reply buttons.'], array_map(static fn ($warning): string => $warning->message, $reply));
    }

    public function test_a_channel_that_declares_everything_gives_no_warnings(): void
    {
        $this->channel('full', ChannelCapability::cases());

        $this->assertSame([], $this->check([
            $this->keyboardNode('inline'),
            ['id' => 'c', 'type' => 'input', 'config' => ['expected_type' => 'contact']],
        ]));
    }

    public function test_an_assistant_without_channels_gives_no_warnings(): void
    {
        $this->assertSame([], $this->check([$this->keyboardNode('inline')]));
    }

    public function test_a_message_without_buttons_or_a_plain_text_message_needs_nothing(): void
    {
        $this->channel('plain');

        $this->assertSame([], $this->check([
            $this->keyboardNode('inline', ['buttons' => []]),
            ['id' => 'm', 'type' => 'send_message', 'config' => ['content_type' => 'text', 'text' => 'Hi']],
        ]));
    }

    public function test_an_inactive_channel_counts_and_lacking_types_are_named_once_each(): void
    {
        $this->channel('plain', [], active: false);
        $this->channel('plain', []);
        $this->channel('other', []);

        $warnings = $this->check([['id' => 'c', 'type' => 'input', 'config' => ['expected_type' => 'contact']]]);

        $this->assertCount(1, $warnings);
        $this->assertSame('Plain, Other cannot ask the user to share a contact.', $warnings[0]->message);
    }

    public function test_warnings_never_invalidate_the_flow_and_need_an_assistant(): void
    {
        $this->channel('plain');

        $nodes = ['c' => [
            'id'      => 'c',
            'type'    => 'input',
            'version' => 1,
            'config'  => ['expected_type' => 'contact', 'variable' => ['name' => 'phone']],
        ]];

        $service = $this->app->make(ValidateFlowService::class);

        $withAssistant = $service->execute($nodes, assistantId: (string) $this->assistant->getKey());
        $this->assertTrue($withAssistant->valid);
        $this->assertCount(1, $withAssistant->warnings);

        $this->assertSame([], $service->execute($nodes)->warnings);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     *
     * @return list<\App\Domains\Flow\DTOs\FlowValidationErrorDto>
     */
    private function check(array $nodes): array
    {
        $map = [];

        foreach ($nodes as $node) {
            $map[$node['id']] = $node;
        }

        return $this->app->make(ChannelCapabilityChecker::class)->check($map, (string) $this->assistant->getKey());
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return array<string, mixed>
     */
    private function keyboardNode(?string $mode, array $config = []): array
    {
        return [
            'id'     => 'n1',
            'type'   => 'send_message',
            'config' => [
                'content_type' => 'text_with_keyboard',
                'buttons'      => [['id' => 'b1', 'label' => 'Yes']],
                ...(null === $mode ? [] : ['keyboard_mode' => $mode]),
                ...$config,
            ],
        ];
    }

    /**
     * @param  list<ChannelCapability>  $capabilities
     */
    private function channel(string $type, array $capabilities = [], bool $active = true): void
    {
        Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $this->assistant->getKey(),
            'tenant_id'    => $this->assistant->tenant_id,
            'type'         => $type,
            'is_active'    => $active,
        ]));

        $declared        = $this->declared ?? [];
        $declared[$type] = new ChannelCapabilities($capabilities);
        $this->declared  = $declared;

        $declared = $this->declared;

        $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($declared): void {
            $mock->shouldReceive('capabilities')->andReturnUsing(
                static fn (string $type): ChannelCapabilities => $declared[$type],
            );
            $mock->shouldReceive('label')->andReturnUsing(static fn (string $type): string => ucfirst($type));
        });
    }
}
