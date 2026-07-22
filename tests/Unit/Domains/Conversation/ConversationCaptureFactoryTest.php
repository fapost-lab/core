<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Conversation;

use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use FAPost\Foundation\DTO\IncomingMedia;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\Media\Enums\MediaKind;
use Tests\TestCase;

final class ConversationCaptureFactoryTest extends TestCase
{
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
}
