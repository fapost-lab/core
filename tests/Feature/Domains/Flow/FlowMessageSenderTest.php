<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowMessageSender;
use FAPost\Foundation\Messaging\DeliveryResult;
use FAPost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;

final class FlowMessageSenderTest extends FeatureTestCase
{
    public function test_it_builds_inline_keyboard_payload_for_latest_active_channel(): void
    {
        $tenantId  = '00000000-0000-0000-0000-000000000001';
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create([
            'external_id' => 'chat-123',
        ]);

        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $tenantId,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => 'flow-1',
            'version'   => 1,
            'name'      => 'Test Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => (string) $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-1',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);

        $sender = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock) use ($session): void {
            $mock->shouldReceive('send')->once()->withArgs(fn ($message): bool => 'chat-123' === $message->chatId
                    && 'keyboard' === $message->payload->type
                    && 'Choose' === $message->payload->text
                    && (string) $session->getKey() === $message->metadata['flow_session_id']
                    && sprintf(
                        '{"session_id":"%s","button_id":"11111111-1111-4111-8111-111111111111"}',
                        $session->getKey(),
                    )
                        === $message->payload->keyboard['inline_keyboard'][0][0]['callback_data'])->andReturn(new DeliveryResult(sent: true, providerMessageId: 'provider-1'));
        });

        $flowSender = new FlowMessageSender($sender);

        $providerMessageId = $flowSender->send($tenantId, (string) $contact->getKey(), (string) $session->getKey(), [
            'node_id'         => 'node-1',
            'idempotency_key' => 'idem-1',
            'session_id'      => (string) $session->getKey(),
            'content_type'    => 'text_with_keyboard',
            'keyboard_mode'   => 'inline',
            'text'            => 'Choose',
            'buttons'         => [
                [
                    'id'    => '11111111-1111-4111-8111-111111111111',
                    'label' => 'Yes',
                    'value' => 'yes',
                    'row'   => 0,
                    'order' => 0,
                ],
            ],
        ]);

        $this->assertSame('provider-1', $providerMessageId);
    }

    public function test_it_builds_reply_keyboard_payload_for_active_channel(): void
    {
        $tenantId  = '00000000-0000-0000-0000-000000000002';
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create(['external_id' => 'chat-456']);

        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $tenantId,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token-2',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => 'flow-2',
            'version'   => 1,
            'name'      => 'Reply Test Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => (string) $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-reply',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);

        $sender = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->withArgs(
                fn ($message): bool => 'keyboard' === $message->payload->type
                    && isset($message->payload->keyboard['keyboard'])
                    && true === $message->payload->keyboard['one_time_keyboard']
                    && true === $message->payload->keyboard['resize_keyboard']
                    && 'Option 1' === $message->payload->keyboard['keyboard'][0][0]['text']
                    && ! array_key_exists('callback_data', $message->payload->keyboard['keyboard'][0][0])
            )->andReturn(new DeliveryResult(sent: true, providerMessageId: 'provider-reply'));
        });

        $flowSender = new FlowMessageSender($sender);

        $providerMessageId = $flowSender->send($tenantId, (string) $contact->getKey(), (string) $session->getKey(), [
            'node_id'         => 'node-reply',
            'idempotency_key' => 'idem-reply',
            'session_id'      => (string) $session->getKey(),
            'content_type'    => 'text_with_keyboard',
            'keyboard_mode'   => 'reply',
            'text'            => 'Choose an option',
            'buttons'         => [
                [
                    'id'    => '44444444-4444-4444-8444-444444444444',
                    'label' => 'Option 1',
                    'value' => 'opt1',
                    'row'   => 0,
                    'order' => 0,
                ],
            ],
        ]);

        $this->assertSame('provider-reply', $providerMessageId);
    }

    public function test_it_builds_image_payload_with_caption(): void
    {
        $tenantId  = '00000000-0000-0000-0000-000000000003';
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create(['external_id' => 'chat-789']);

        $channel = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $tenantId,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token-3',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $tenantId,
            'flow_id'   => 'flow-3',
            'version'   => 1,
            'name'      => 'Image Test Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => (string) $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-img',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);

        $sender = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->withArgs(
                fn ($message): bool => 'photo' === $message->payload->type
                    && 'https://example.com/photo.jpg' === ($message->payload->media['photo'] ?? null)
                    && 'Nice photo' === $message->payload->text
            )->andReturn(new DeliveryResult(sent: true, providerMessageId: 'provider-img'));
        });

        $flowSender        = new FlowMessageSender($sender);
        $providerMessageId = $flowSender->send($tenantId, (string) $contact->getKey(), (string) $session->getKey(), [
            'node_id'         => 'node-img',
            'idempotency_key' => 'idem-img',
            'session_id'      => (string) $session->getKey(),
            'content_type'    => 'image',
            'media_url'       => 'https://example.com/photo.jpg',
            'caption'         => 'Nice photo',
        ]);

        $this->assertSame('provider-img', $providerMessageId);
    }
}
