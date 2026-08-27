<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * What kind of payload an `input` node expects from the user.
 *
 * The enum drives three things at runtime:
 *  - which incoming message types are accepted (text vs. media vs. platform-native
 *    contact/location messages vs. inline-keyboard callbacks);
 *  - how the raw payload is parsed/normalized before being stored
 *    (e.g. `number` → float, `phone` → E.164);
 *  - whether the input node renders its own keyboard before parking
 *    (select/confirm).
 */
enum InputExpectedType: string
{
    case Text     = 'text';
    case Number   = 'number';
    case Email    = 'email';
    case Phone    = 'phone';
    case Date     = 'date';
    case Select   = 'select';
    case Confirm  = 'confirm';
    case Contact  = 'contact';
    case Location = 'location';
    case File     = 'file';
    case Image    = 'image';
    case Document = 'document';
    case Video    = 'video';
    case Voice    = 'voice';
    case Audio    = 'audio';

    /** @return list<self> */
    public static function mediaTypes(): array
    {
        return [self::File, self::Image, self::Document, self::Video, self::Voice, self::Audio];
    }

    /**
     * Text-derived input that arrives via a plain Telegram message and must be
     * parsed/validated against a string format (length, regex, number range, etc.).
     */
    public function isTextual(): bool
    {
        return match ($this) {
            self::Text, self::Number, self::Email, self::Phone, self::Date => true,
            default                                                        => false,
        };
    }

    /** Media attachments handled through the media ingest pipeline. */
    public function isMedia(): bool
    {
        return in_array($this, self::mediaTypes(), true);
    }

    /**
     * Renders its own inline keyboard before parking — the user is expected to press
     * a button, and the callback's button value is what we store.
     */
    public function isSelect(): bool
    {
        return self::Select === $this || self::Confirm === $this;
    }

    /**
     * Platform-native message types (Telegram contact share / location share).
     * Require channel adapter support to be normalized into IncomingMessage.
     */
    public function isPlatformNative(): bool
    {
        return self::Contact === $this || self::Location === $this;
    }
}
