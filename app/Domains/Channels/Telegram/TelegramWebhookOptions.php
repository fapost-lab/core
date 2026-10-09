<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

/**
 * What a Telegram channel's `config` may say about its webhook: the update types Telegram can deliver and the
 * bounds of the delivery parallelism. {@see TelegramWebhookRegistrar} reads both keys when it registers the webhook.
 */
final class TelegramWebhookOptions
{
    /**
     * The update types a webhook may ask for, in the order a form lists them.
     *
     * @var list<string>
     */
    public const array ALLOWED_UPDATES = [
        'message',
        'edited_message',
        'channel_post',
        'edited_channel_post',
        'business_connection',
        'business_message',
        'edited_business_message',
        'deleted_business_messages',
        'message_reaction',
        'message_reaction_count',
        'inline_query',
        'chosen_inline_result',
        'callback_query',
        'shipping_query',
        'pre_checkout_query',
        'purchased_paid_media',
        'poll',
        'poll_answer',
        'my_chat_member',
        'chat_member',
        'chat_join_request',
        'chat_boost',
        'removed_chat_boost',
        'managed_bot',
    ];

    public const int MAX_CONNECTIONS_MIN = 1;

    public const int MAX_CONNECTIONS_MAX = 100;

    public const int MAX_CONNECTIONS_DEFAULT = 40;
}
