<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use Fapost\Foundation\Messaging\Button;
use Fapost\Foundation\Messaging\ButtonAction;
use Fapost\Foundation\Messaging\ButtonSet;

/**
 * Translates channel-neutral buttons into the Bot API `reply_markup`.
 *
 * Callback buttons become an inline keyboard under the message; every other kind becomes a one-time reply keyboard.
 */
final class TelegramReplyMarkup
{
    /**
     * @return array<string, mixed>
     */
    public static function fromButtons(ButtonSet $buttons): array
    {
        if ($buttons->expectsCallback()) {
            return ['inline_keyboard' => self::rows($buttons, self::inlineButton(...))];
        }

        return [
            'keyboard'          => self::rows($buttons, self::replyButton(...)),
            'one_time_keyboard' => true,
            'resize_keyboard'   => true,
        ];
    }

    /**
     * @param  callable(Button): array<string, mixed>  $translate
     *
     * @return list<list<array<string, mixed>>>
     */
    private static function rows(ButtonSet $buttons, callable $translate): array
    {
        return array_map(
            static fn (array $row): array => array_map($translate, $row),
            $buttons->rows,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function inlineButton(Button $button): array
    {
        return [
            'text'          => $button->label,
            'callback_data' => (string) $button->payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function replyButton(Button $button): array
    {
        return match ($button->action) {
            ButtonAction::ShareContact  => ['text' => $button->label, 'request_contact' => true],
            ButtonAction::ShareLocation => ['text' => $button->label, 'request_location' => true],
            default                     => ['text' => $button->label],
        };
    }
}
