<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

use FAPost\Foundation\DTO\IncomingMessageType;

/**
 * Canonical content-type vocabulary for the transcript, normalizing the various
 * inbound ({@see IncomingMessageType}) and outbound (message-payload) taxonomies
 * into one dictionary (spec §6). Keyboards ride inside `payload`, so an outbound
 * keyboard message is logged by its underlying content type, not a distinct case.
 */
enum MessageContentType: string
{
    case Text     = 'text';
    case Photo    = 'photo';
    case Document = 'document';
    case Video    = 'video';
    case Voice    = 'voice';
    case Audio    = 'audio';
    case Location = 'location';
    case Contact  = 'contact';
    case Callback = 'callback';
    case Unknown  = 'unknown';

    /**
     * Map a normalized inbound message type to the canonical vocabulary.
     */
    public static function fromInbound(IncomingMessageType $type): self
    {
        return match ($type) {
            IncomingMessageType::Text          => self::Text,
            IncomingMessageType::Photo         => self::Photo,
            IncomingMessageType::Document      => self::Document,
            IncomingMessageType::Video         => self::Video,
            IncomingMessageType::Voice         => self::Voice,
            IncomingMessageType::Audio         => self::Audio,
            IncomingMessageType::Location      => self::Location,
            IncomingMessageType::Contact       => self::Contact,
            IncomingMessageType::CallbackQuery => self::Callback,
            IncomingMessageType::Unknown       => self::Unknown,
        };
    }

    /**
     * Map an outbound message-payload type string to the canonical vocabulary.
     * Accepts both `photo` (payload) and `image` (SendMessageContentType) spellings.
     */
    public static function fromOutbound(string $payloadType): self
    {
        return match ($payloadType) {
            'text', 'text_with_keyboard', 'keyboard' => self::Text,
            'photo', 'image'                         => self::Photo,
            'document'                               => self::Document,
            'video'                                  => self::Video,
            'voice'                                  => self::Voice,
            'audio'                                  => self::Audio,
            default                                  => self::Unknown,
        };
    }
}
