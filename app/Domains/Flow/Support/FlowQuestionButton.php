<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;

/**
 * The payload of an `ask_person` question's button: `q1`, the question's 32-hex token and the button's index as two
 * hex digits — 36 bytes, inside Foundation's `Button::MAX_PAYLOAD_BYTES` and never mistaken for
 * {@see CallbackDataCodec}'s 64-hex payload.
 *
 * The routing pipeline swallows every press in this format, a known token or not: a press of a question whose row is
 * gone (pruned with its run) must not reach the addressee's own conversation as input.
 */
final class FlowQuestionButton
{
    /** The most buttons a question may have; the index is two hex digits. */
    public const int MAX_BUTTONS = 8;
    private const string PREFIX  = 'q1';

    private const string PATTERN = '/^q1([0-9a-f]{32})([0-9a-f]{2})$/';

    public static function encode(string $token, int $index): string
    {
        return self::PREFIX . $token . sprintf('%02x', $index);
    }

    /**
     * The token and button index of a press of a question's button, or null when the message is not one.
     *
     * @return array{token: string, index: int}|null
     */
    public static function pressedIn(IncomingMessage $message): ?array
    {
        if (IncomingMessageType::CallbackQuery !== $message->type || null === $message->text) {
            return null;
        }

        if (1 !== preg_match(self::PATTERN, $message->text, $matches)) {
            return null;
        }

        return ['token' => $matches[1], 'index' => (int) hexdec($matches[2])];
    }
}
