<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowInlineKeyboardEditor;
use Fapost\Foundation\Messaging\ChannelCapabilities;
use Fapost\Foundation\Messaging\ChannelCapability;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;

final class FlowInlineKeyboardEditorTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-0000000000e1';

    private Contact $contact;

    private FlowSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $assistant     = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $this->contact = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-edit']);

        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $this->contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => self::TENANT_ID,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Editor Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $this->session = FlowSession::query()->create([
            'tenant_id'          => self::TENANT_ID,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $this->contact->getKey(),
            'flow_definition_id' => (string) $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);
    }

    public function test_a_channel_without_remove_buttons_is_sent_nothing(): void
    {
        $sender = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });

        $this->editor($sender, new ChannelCapabilities([ChannelCapability::CallbackButtons]))->removeKeyboard(
            self::TENANT_ID,
            (string) $this->contact->getKey(),
            (string) $this->session->getKey(),
            '4242',
        );
    }

    public function test_a_channel_with_remove_buttons_is_sent_the_neutral_operation(): void
    {
        $sender = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->withArgs(
                fn (OutboundMessage $message): bool => 'remove_keyboard' === $message->payload->type
                    && 'telegram' === $message->channelType
                    && 'chat-edit' === $message->chatId
                    && '4242' === $message->metadata['edit_message_id']
            )->andReturn(new DeliveryResult(sent: true));
        });

        $this->editor($sender, new ChannelCapabilities([ChannelCapability::RemoveButtons]))->removeKeyboard(
            self::TENANT_ID,
            (string) $this->contact->getKey(),
            (string) $this->session->getKey(),
            '4242',
        );
    }

    private function editor(OutboundMessageSenderInterface $sender, ChannelCapabilities $capabilities): FlowInlineKeyboardEditor
    {
        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($capabilities): void {
            $mock->shouldReceive('capabilities')->with('telegram')->andReturn($capabilities);
        });

        return new FlowInlineKeyboardEditor($sender, $registry);
    }
}
