<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Exceptions\ConversationReplyUndeliverableException;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\DTO\DispatchResult;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaBlob;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FakeUsageMeter;

/**
 * Covers {@see \App\Domains\Conversation\Services\ConversationReplyService} —
 * the envelope it hands to {@see \App\Domains\Messaging\MessageSender} must
 * carry staff attribution so {@see \App\Domains\Conversation\Capture\ConversationCaptureFactory}
 * (covered separately in ConversationCaptureFactoryTest) logs it as a staff
 * reply, and it must send on the thread's own channel/chat, not a guessed one.
 */
final class ConversationReplyServiceTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function attachments(): array
    {
        return [
            'jpeg goes as a photo'    => ['image/jpeg', 'photo', 'photo'],
            'heic goes as a document' => ['image/heic', 'document', 'document'],
        ];
    }

    public function test_send_builds_staff_attributed_outbound_message_on_thread_channel(): void
    {
        [$conversation, $contact, $channel] = $this->threadWithLinkedContact();

        $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock) use ($conversation, $contact, $channel): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn (OutboundMessage $message): bool => $message->chatId === $contact->external_id
                        && $message->channelId === (string) $channel->getKey()
                        && $message->channelType === ChannelTypeEnum::Telegram->value
                        && 'Thanks for reaching out' === $message->payload->text
                        && $message->metadata['contact_id'] === (string) $conversation->contact_id
                        && $message->metadata['assistant_id'] === (string) $conversation->assistant_id
                        && $message->metadata['origin'] === MessageOrigin::Staff->value
                        && ($message->metadata['origin_ref']['staff_user_id'] ?? null) === 'staff-1'
                        // Operator prose must not be sent as HTML markup: a bare
                        // "R&D" or "5 < 10" would be malformed and Telegram would
                        // reject the whole message.
                        && ! array_key_exists('parse_mode', $message->metadata))
                ->andReturn(new DeliveryResult(sent: true, providerMessageId: 'provider-1'));
        });

        $result = app(ConversationReplyServiceInterface::class)->send($conversation, 'Thanks for reaching out', 'staff-1');

        $this->assertTrue($result->sent);
        $this->assertSame('provider-1', $result->providerMessageId);
    }

    public function test_a_submission_id_makes_the_idempotency_key_stable_across_retries(): void
    {
        [$conversation] = $this->threadWithLinkedContact();
        $keys           = [];

        $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock) use (&$keys): void {
            $mock->shouldReceive('send')->andReturnUsing(function (OutboundMessage $message) use (&$keys): DeliveryResult {
                $keys[] = $message->idempotencyKey;

                return new DeliveryResult(sent: true);
            });
        });

        $service = app(ConversationReplyServiceInterface::class);
        $service->send($conversation, 'hi', 'staff-1', null, 'req-1');
        $service->send($conversation, 'hi', 'staff-1', null, 'req-1');
        $service->send($conversation, 'hi', 'staff-1');

        $expected = 'staff_reply:' . $conversation->getKey() . ':req-1';
        $this->assertSame([$expected, $expected], array_slice($keys, 0, 2));
        $this->assertNotSame($expected, $keys[2]);
    }

    public function test_send_throws_when_contact_has_no_channel_linkage(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-999']);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'is_active'    => true,
        ]));

        // No ChannelContact linkage created — the reply has nowhere to go.
        $conversation = Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
        ]);

        $this->expectException(ConversationReplyUndeliverableException::class);

        app(ConversationReplyServiceInterface::class)->send($conversation, 'hi', 'staff-1');
    }

    public function test_send_throws_when_channel_is_inactive(): void
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-998']);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'is_active'    => false,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
        ]);

        $this->expectException(ConversationReplyUndeliverableException::class);

        app(ConversationReplyServiceInterface::class)->send($conversation, 'hi', 'staff-1');
    }

    public function test_media_reply_refused_by_the_volume_limit_is_not_uploaded_or_sent(): void
    {
        [$conversation] = $this->threadWithLinkedContact();
        $mediaFile      = $this->mediaFile();

        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));

        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::denying(10, 10));
        $this->mock(MediaDispatcherInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('ensureUploadedToChannel');
        });
        $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('send');
        });

        $this->expectException(VolumeLimitReachedException::class);

        app(ConversationReplyServiceInterface::class)->send($conversation, 'see attached', 'staff-1', (string) $mediaFile->getKey());
    }

    public function test_media_reply_spends_one_unit_under_the_key_the_message_carries(): void
    {
        [$conversation] = $this->threadWithLinkedContact();
        $mediaFile      = $this->mediaFile();

        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));
        $meter   = FakeUsageMeter::allowing();
        $sentKey = null;

        $this->app->instance(UsageMeterInterface::class, $meter);
        $this->mock(MediaDispatcherInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureUploadedToChannel')->once()->andReturn(new DispatchResult(providerFileId: 'file-1', alreadyDelivered: false));
        });
        $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock) use (&$sentKey): void {
            $mock->shouldReceive('send')->once()->andReturnUsing(function (OutboundMessage $message) use (&$sentKey): DeliveryResult {
                $sentKey = $message->idempotencyKey;

                return new DeliveryResult(sent: true, providerMessageId: 'p-1');
            });
        });

        app(ConversationReplyServiceInterface::class)->send($conversation, 'see attached', 'staff-1', (string) $mediaFile->getKey());

        $this->assertCount(1, $meter->units);
        $this->assertSame('outbound_messages', $meter->units[0]->key);
        $this->assertSame('msg:' . $sentKey, $meter->units[0]->unitKey);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('attachments')]
    public function test_a_cached_attachment_goes_out_the_way_it_was_uploaded(string $mime, string $type, string $field): void
    {
        [$conversation] = $this->threadWithLinkedContact();
        $mediaFile      = $this->mediaFile($mime);

        $this->app->make(TenantContextInterface::class)->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));
        $this->app->instance(UsageMeterInterface::class, FakeUsageMeter::allowing());
        $this->mock(MediaDispatcherInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('ensureUploadedToChannel')->once()->andReturn(new DispatchResult(providerFileId: 'file-1', alreadyDelivered: false));
        });
        $this->mock(OutboundMessageSenderInterface::class, function (MockInterface $mock) use ($type, $field): void {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(fn (OutboundMessage $message): bool => $type === $message->payload->type && 'file-1' === ($message->payload->media[$field] ?? null))
                ->andReturn(new DeliveryResult(sent: true, providerMessageId: 'p-1'));
        });

        app(ConversationReplyServiceInterface::class)->send($conversation, 'see attached', 'staff-1', (string) $mediaFile->getKey());
    }

    private function mediaFile(string $mime = 'image/jpeg'): MediaFile
    {
        $blob = MediaBlob::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'content_hash' => str_repeat('e', 64),
            'storage_path' => 'tenants/test/media/e.jpg',
            'storage_disk' => 'local',
            'size'         => 10,
            'mime_type'    => $mime,
        ]);

        return MediaFile::query()->create([
            'tenant_id' => self::TENANT_ID,
            'blob_id'   => $blob->id,
            'name'      => 'e.jpg',
            'kind'      => MediaKind::Image,
            'metadata'  => [],
            'source'    => MediaSource::Upload,
        ]);
    }

    /**
     * @return array{0: Conversation, 1: Contact, 2: Channel}
     */
    private function threadWithLinkedContact(): array
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
        $contact   = Contact::factory()->forTenant(self::TENANT_ID)->create(['external_id' => 'chat-123']);
        $channel   = Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => self::TENANT_ID,
            'type'         => ChannelTypeEnum::Telegram,
            'token'        => 'bot-token',
            'is_active'    => true,
        ]));

        ChannelContact::query()->create([
            'contact_id'          => $contact->getKey(),
            'channel_id'          => $channel->getKey(),
            'last_interaction_at' => now(),
        ]);

        $conversation = Conversation::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'contact_id'   => $contact->getKey(),
            'channel_id'   => $channel->getKey(),
            'platform'     => 'telegram',
        ]);

        return [$conversation, $contact, $channel];
    }
}
