<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Conversation;

use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Fapost\Foundation\Media\Enums\MediaKind;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ConversationCaptureFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{MessageOrigin}>
     */
    public static function nonStaffOrigins(): iterable
    {
        yield 'flow' => [MessageOrigin::Flow];
        yield 'broadcast' => [MessageOrigin::Broadcast];
        yield 'notify' => [MessageOrigin::Notify];
        yield 'command' => [MessageOrigin::Command];
        yield 'system' => [MessageOrigin::System];
    }
    public function test_maps_inbound_text_message(): void
    {
        $message = new IncomingMessage(
            updateId: 'update-1',
            externalUserId: 'u-1',
            externalChatId: 'c-1',
            text: 'Hello there',
            type: IncomingMessageType::Text,
            platform: 'telegram',
            payload: ['raw' => true],
            media: [],
        );

        $entry = (new ConversationCaptureFactory())->forInbound(
            tenantId: 't-1',
            assistantId: 'a-1',
            contactId: 'ct-1',
            channelId: 'ch-1',
            message: $message,
            idempotencyKey: 'update-1',
        );

        $this->assertSame('t-1', $entry->tenantId);
        $this->assertSame('ct-1', $entry->contactId);
        $this->assertSame(MessageDirection::Inbound, $entry->direction);
        $this->assertSame(MessageSenderType::Contact, $entry->senderType);
        $this->assertSame(MessageContentType::Text, $entry->contentType);
        $this->assertSame(MessageOrigin::Flow, $entry->origin);
        $this->assertSame('Hello there', $entry->text);
        $this->assertSame(['raw' => true], $entry->payload);
        $this->assertSame('update-1', $entry->idempotencyKey);
        $this->assertSame([], $entry->media);
    }

    public function test_maps_inbound_photo_with_media_descriptor(): void
    {
        $message = new IncomingMessage(
            updateId: 'update-2',
            externalUserId: 'u-1',
            externalChatId: 'c-1',
            text: null,
            type: IncomingMessageType::Photo,
            platform: 'telegram',
            payload: [],
            media: [
                new IncomingMedia(
                    providerFileId: 'AgAC-file',
                    kind: MediaKind::Image,
                    mimeType: 'image/jpeg',
                    fileName: 'photo.jpg',
                    size: 1024,
                ),
            ],
        );

        $entry = (new ConversationCaptureFactory())->forInbound(
            tenantId: 't-1',
            assistantId: 'a-1',
            contactId: 'ct-1',
            channelId: 'ch-1',
            message: $message,
            idempotencyKey: 'update-2',
        );

        $this->assertSame(MessageContentType::Photo, $entry->contentType);
        $this->assertNull($entry->text);
        $this->assertCount(1, $entry->media);
        $this->assertSame([
            'provider_file_id' => 'AgAC-file',
            'kind'             => 'image',
            'mime'             => 'image/jpeg',
            'file_name'        => 'photo.jpg',
            'size'             => 1024,
            'status'           => 'pending',
        ], $entry->media[0]);
    }

    /**
     * The inbox reply funnel (spec: operator reply page) marks the outbound
     * message's origin as `staff` with a `staff_user_id` origin_ref; the
     * factory must attribute the transcript row to the operator, not the
     * assistant, for both sender_type and sender_staff_user_id.
     */
    public function test_maps_outbound_staff_reply_with_sender_attribution(): void
    {
        $message = new OutboundMessage(
            idempotencyKey: 'staff_reply:conv-1:ulid-1',
            tenantId: 't-1',
            channelId: 'ch-1',
            channelType: 'telegram',
            transportToken: 'token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Hello from support'),
            metadata: [
                'contact_id'   => 'ct-1',
                'assistant_id' => 'a-1',
                'origin'       => MessageOrigin::Staff->value,
                'origin_ref'   => ['staff_user_id' => 'staff-1'],
            ],
        );

        $entry = (new ConversationCaptureFactory())->forOutbound(
            $message,
            new DeliveryResult(sent: true, providerMessageId: 'provider-msg-1'),
        );

        $this->assertNotNull($entry);
        $this->assertSame(MessageDirection::Outbound, $entry->direction);
        $this->assertSame(MessageSenderType::Staff, $entry->senderType);
        $this->assertSame('staff-1', $entry->senderStaffUserId);
        $this->assertSame(MessageOrigin::Staff, $entry->origin);
        $this->assertSame('ct-1', $entry->contactId);
        $this->assertSame('a-1', $entry->assistantId);
        $this->assertSame('Hello from support', $entry->text);
    }

    /**
     * Without contact/assistant identity in metadata the factory refuses to
     * build an entry (spec §7.2) — the capture site must not silently drop
     * this, but the factory's contract is to signal "nothing to log" via null.
     */
    public function test_forOutbound_returns_null_without_capture_context(): void
    {
        $message = new OutboundMessage(
            idempotencyKey: 'k-1',
            tenantId: 't-1',
            channelId: 'ch-1',
            channelType: 'telegram',
            transportToken: 'token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'no metadata'),
        );

        $entry = (new ConversationCaptureFactory())->forOutbound(
            $message,
            new DeliveryResult(sent: true),
        );

        $this->assertNull($entry);
    }

    /**
     * A staff-origin reply whose origin_ref omits `staff_user_id` (malformed
     * or stripped metadata) must not blow up the pipeline — the entry still
     * attributes sender_type to Staff, but sender_staff_user_id degrades to
     * null rather than throwing.
     */
    public function test_maps_outbound_staff_reply_without_staff_user_id_in_origin_ref(): void
    {
        $message = new OutboundMessage(
            idempotencyKey: 'staff_reply:conv-2:ulid-2',
            tenantId: 't-1',
            channelId: 'ch-1',
            channelType: 'telegram',
            transportToken: 'token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Hello again'),
            metadata: [
                'contact_id'   => 'ct-1',
                'assistant_id' => 'a-1',
                'origin'       => MessageOrigin::Staff->value,
                'origin_ref'   => [],
            ],
        );

        $entry = (new ConversationCaptureFactory())->forOutbound(
            $message,
            new DeliveryResult(sent: true, providerMessageId: 'provider-msg-2'),
        );

        $this->assertNotNull($entry);
        $this->assertSame(MessageSenderType::Staff, $entry->senderType);
        $this->assertNull($entry->senderStaffUserId);
    }

    /**
     * Every non-staff origin must keep the pre-takeover behaviour: the
     * transcript row is attributed to the assistant and carries no staff
     * user id, even if origin_ref happens to contain one (a staff_user_id
     * cannot leak sender attribution without a staff origin declaring it).
     */
    #[DataProvider('nonStaffOrigins')]
    public function test_maps_outbound_non_staff_origin_to_assistant_sender(MessageOrigin $origin): void
    {
        $message = new OutboundMessage(
            idempotencyKey: 'reply:' . $origin->value,
            tenantId: 't-1',
            channelId: 'ch-1',
            channelType: 'telegram',
            transportToken: 'token',
            chatId: 'chat-1',
            payload: new MessagePayload(type: 'text', text: 'Automated reply'),
            metadata: [
                'contact_id'   => 'ct-1',
                'assistant_id' => 'a-1',
                'origin'       => $origin->value,
                'origin_ref'   => ['staff_user_id' => 'staff-should-be-ignored'],
            ],
        );

        $entry = (new ConversationCaptureFactory())->forOutbound(
            $message,
            new DeliveryResult(sent: true, providerMessageId: 'provider-msg-' . $origin->value),
        );

        $this->assertNotNull($entry);
        $this->assertSame(MessageSenderType::Assistant, $entry->senderType);
        $this->assertNull($entry->senderStaffUserId);
        $this->assertSame($origin, $entry->origin);
    }
}
