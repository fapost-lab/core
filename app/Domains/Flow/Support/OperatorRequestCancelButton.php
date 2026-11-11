<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

/**
 * The "Cancel request" button a `request_operator` node puts under its "request sent" message.
 *
 * Its id is reserved for the whole platform, so the routing pipeline recognises a press by the id alone, before it
 * checks whether an operator handles the thread: while the thread is `queued` every other message is held back from the
 * flow, and after the request has ended a late press must not reach the flow as input or start one. The payload is the
 * ordinary {@see CallbackDataCodec} one: the session that sent the button and this id.
 */
final class OperatorRequestCancelButton
{
    /** A fixed UUID, so the codec carries it like any other button id. */
    public const string ID = '0e7a7c1e-5f1a-4c4b-9c8a-6f70e4a7c0de';

    /**
     * The session whose button was pressed, or null when the text is not a press of this button.
     */
    public static function pressedIn(?string $text): ?string
    {
        $payload = CallbackDataCodec::decode($text);

        if (null === $payload || self::ID !== mb_strtolower($payload['button_id'])) {
            return null;
        }

        return mb_strtolower($payload['session_id']);
    }
}
