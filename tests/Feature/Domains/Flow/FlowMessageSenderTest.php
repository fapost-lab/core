<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\FlowMessageSender;
use App\Domains\Flow\Support\CallbackDataCodec;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\DTO\DispatchResult;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeUsageMeter;
use Tests\Support\UsageGates;

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
            'flow_id'   => (string) Str::uuid(),
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
                                                                                  && CallbackDataCodec::encode(
                                                                                      (string)$session->getKey(),
                                                                                      '11111111-1111-4111-8111-111111111111',
                                                                                  ) === $message->payload->keyboard['inline_keyboard'][0][0]['callback_data'])->andReturn(
                                                                                      new DeliveryResult(sent: true, providerMessageId: 'provider-1')
                                                                                  );
        });

        $flowSender = new FlowMessageSender(
            $sender,
            Mockery::mock(MediaDispatcherInterface::class),
            $this->recordingLogger(),
            new ConversationCaptureFactory(),
            UsageGates::gate(),
        );

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
            'flow_id'   => (string) Str::uuid(),
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

        $flowSender = new FlowMessageSender(
            $sender,
            Mockery::mock(MediaDispatcherInterface::class),
            $this->recordingLogger(),
            new ConversationCaptureFactory(),
            UsageGates::gate(),
        );

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
            'flow_id'   => (string) Str::uuid(),
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

        $blob = MediaBlob::query()->create([
            'tenant_id'    => $tenantId,
            'content_hash' => str_repeat('a', 64),
            'storage_path' => 'tenants/test/media/foo.jpg',
            'storage_disk' => 'local',
            'size'         => 100,
            'mime_type'    => 'image/jpeg',
        ]);

        $mediaFile = MediaFile::query()->create([
            'tenant_id' => $tenantId,
            'blob_id'   => $blob->id,
            'name'      => 'photo.jpg',
            'kind'      => MediaKind::Image,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);

        $sender = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('send')->once()->withArgs(
                fn ($message): bool => 'photo' === $message->payload->type
                                       && 'cached-file-id' === ($message->payload->media['photo'] ?? null)
                    && 'Nice photo' === $message->payload->text
            )->andReturn(new DeliveryResult(sent: true, providerMessageId: 'provider-img'));
        });

        $dispatcher = Mockery::mock(MediaDispatcherInterface::class);
        $dispatcher->shouldReceive('ensureUploadedToChannel')->once()->andReturn(
            new DispatchResult(providerFileId: 'cached-file-id', alreadyDelivered: false),
        );

        $flowSender        = new FlowMessageSender($sender, $dispatcher, $this->recordingLogger(), new ConversationCaptureFactory(), UsageGates::gate());
        $providerMessageId = $flowSender->send($tenantId, (string) $contact->getKey(), (string) $session->getKey(), [
            'node_id'         => 'node-img',
            'idempotency_key' => 'idem-img',
            'session_id'      => (string) $session->getKey(),
            'content_type'    => 'image',
            'media_file_id'   => $mediaFile->id,
            'caption'         => 'Nice photo',
        ]);

        $this->assertSame('provider-img', $providerMessageId);
    }

    public function test_image_send_skips_outbound_when_dispatcher_already_delivered(): void
    {
        $tenantId  = '00000000-0000-0000-0000-000000000001';
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create([
            'external_id' => 'chat-uas',
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
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Test Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'contact_id'         => $contact->getKey(),
            'assistant_id'       => $assistant->getKey(),
            'flow_id'            => $definition->flow_id,
            'flow_definition_id' => (string)$definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-uas',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);

        $blob = MediaBlob::query()->create([
            'tenant_id'    => $tenantId,
            'content_hash' => str_repeat('b', 64),
            'storage_path' => 'tenants/test/media/bar.jpg',
            'storage_disk' => 'local',
            'size'         => 50,
            'mime_type'    => 'image/jpeg',
        ]);

        $mediaFile = MediaFile::query()->create([
            'tenant_id' => $tenantId,
            'blob_id'   => $blob->id,
            'name'      => 'bar.jpg',
            'kind'      => MediaKind::Image,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);

        $sender = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });

        $dispatcher = Mockery::mock(MediaDispatcherInterface::class);
        $dispatcher->shouldReceive('ensureUploadedToChannel')->once()->andReturn(
            new DispatchResult(
                providerFileId: 'tg-file-id',
                alreadyDelivered: true,
                deliveredMessageId: '999',
            ),
        );

        $logger     = $this->recordingLogger();
        $flowSender = new FlowMessageSender($sender, $dispatcher, $logger, new ConversationCaptureFactory(), UsageGates::gate());

        $providerMessageId = $flowSender->send($tenantId, (string)$contact->getKey(), (string)$session->getKey(), [
            'node_id'         => 'node-uas',
            'idempotency_key' => 'idem-uas',
            'session_id'      => (string)$session->getKey(),
            'content_type'    => 'image',
            'media_file_id'   => $mediaFile->id,
            'caption'         => null,
        ]);

        $this->assertSame('999', $providerMessageId);

        // The upload-as-send path bypasses MessageSender, so FlowMessageSender must
        // capture the outbound transcript itself.
        $this->assertCount(1, $logger->entries);
        $entry = $logger->entries[0];
        $this->assertSame('999', $entry->providerMessageId);
        $this->assertSame((string)$contact->getKey(), $entry->contactId);
        $this->assertSame((string)$assistant->getKey(), $entry->assistantId);
    }

    public function test_media_send_refused_by_the_volume_limit_does_not_upload_or_deliver(): void
    {
        [$tenantId, $contact, $session, $mediaFile] = $this->mediaScenario('c');

        $sender = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });
        $dispatcher = Mockery::mock(MediaDispatcherInterface::class);
        $dispatcher->shouldNotReceive('ensureUploadedToChannel');

        $meter      = FakeUsageMeter::denying(5, 5);
        $flowSender = new FlowMessageSender($sender, $dispatcher, $this->recordingLogger(), new ConversationCaptureFactory(), UsageGates::gate($meter));

        try {
            $flowSender->send($tenantId, (string) $contact->getKey(), (string) $session->getKey(), [
                'node_id'         => 'node-m',
                'idempotency_key' => 'idem-m',
                'session_id'      => (string) $session->getKey(),
                'content_type'    => 'image',
                'media_file_id'   => $mediaFile->id,
                'caption'         => null,
            ]);
            $this->fail('Expected VolumeLimitReachedException.');
        } catch (VolumeLimitReachedException $exception) {
            $this->assertSame('outbound_messages', $exception->key);
        }
    }

    public function test_media_send_asks_for_the_same_unit_before_the_upload_and_in_the_message_sender(): void
    {
        [$tenantId, $contact, $session, $mediaFile] = $this->mediaScenario('d');

        $meter    = FakeUsageMeter::allowing();
        $captured = null;
        $sender   = $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock) use (&$captured): void {
            $mock->shouldReceive('send')->once()->andReturnUsing(function ($message) use (&$captured): DeliveryResult {
                $captured = $message->idempotencyKey;

                return new DeliveryResult(sent: true, providerMessageId: 'p');
            });
        });
        $dispatcher = Mockery::mock(MediaDispatcherInterface::class);
        $dispatcher->shouldReceive('ensureUploadedToChannel')->once()->andReturn(
            new DispatchResult(providerFileId: 'cached', alreadyDelivered: false),
        );

        $flowSender = new FlowMessageSender($sender, $dispatcher, $this->recordingLogger(), new ConversationCaptureFactory(), UsageGates::gate($meter));

        $flowSender->send($tenantId, (string) $contact->getKey(), (string) $session->getKey(), [
            'node_id'         => 'node-m',
            'idempotency_key' => 'idem-m',
            'session_id'      => (string) $session->getKey(),
            'content_type'    => 'image',
            'media_file_id'   => $mediaFile->id,
            'caption'         => null,
        ]);

        // One unit asked for before the upload, under the very key the message carries on to MessageSender.
        $this->assertCount(1, $meter->units);
        $this->assertSame('msg:' . $captured, $meter->units[0]->unitKey);
    }

    /**
     * @return array{0: string, 1: Contact, 2: FlowSession, 3: MediaFile}
     */
    private function mediaScenario(string $hash): array
    {
        $tenantId  = '00000000-0000-0000-0000-000000000001';
        $assistant = Assistant::factory()->create(['tenant_id' => $tenantId]);
        $contact   = Contact::factory()->forTenant($tenantId)->create(['external_id' => 'chat-' . $hash]);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
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
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Test Flow',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $session = FlowSession::query()->create([
            'tenant_id'          => $tenantId,
            'contact_id'         => $contact->getKey(),
            'assistant_id'       => $assistant->getKey(),
            'flow_id'            => $definition->flow_id,
            'flow_definition_id' => (string) $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'node-m',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);

        $blob = MediaBlob::query()->create([
            'tenant_id'    => $tenantId,
            'content_hash' => str_repeat($hash, 64),
            'storage_path' => 'tenants/test/media/' . $hash . '.jpg',
            'storage_disk' => 'local',
            'size'         => 50,
            'mime_type'    => 'image/jpeg',
        ]);

        $mediaFile = MediaFile::query()->create([
            'tenant_id' => $tenantId,
            'blob_id'   => $blob->id,
            'name'      => $hash . '.jpg',
            'kind'      => MediaKind::Image,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);

        return [$tenantId, $contact, $session, $mediaFile];
    }

    private function recordingLogger(): ConversationLoggerInterface
    {
        return new class () implements ConversationLoggerInterface {
            /** @var list<MessageLogEntry> */
            public array $entries = [];

            public function log(MessageLogEntry $entry): void
            {
                $this->entries[] = $entry;
            }

            public function updateDeliveryStatus(string $providerMessageId, DeliveryStatus $status): void
            {
            }
        };
    }
}
