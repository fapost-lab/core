<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use Tests\TestCase;

/**
 * Public identity of a channel — the bot handle and the deep link staff open
 * from the channels grid and flows template into messages.
 */
final class ChannelPublicLinkTest extends TestCase
{
    public function test_telegram_channel_exposes_handle_and_deep_link(): void
    {
        $channel = $this->channel(ChannelTypeEnum::Telegram, 'fapost_demo_bot');

        $this->assertSame('fapost_demo_bot', $channel->publicUsername());
        $this->assertSame('@fapost_demo_bot', $channel->publicHandle());
        $this->assertSame('https://t.me/fapost_demo_bot', $channel->publicUrl());
    }

    public function test_telegram_channel_without_registered_bot_has_no_link(): void
    {
        $channel = $this->channel(ChannelTypeEnum::Telegram, null);

        $this->assertNull($channel->publicUsername());
        $this->assertNull($channel->publicHandle());
        $this->assertNull($channel->publicUrl());
    }

    public function test_blank_username_is_treated_as_missing(): void
    {
        $channel = $this->channel(ChannelTypeEnum::Telegram, '');

        $this->assertNull($channel->publicHandle());
        $this->assertNull($channel->publicUrl());
    }

    public function test_whatsapp_channel_has_no_public_link_yet(): void
    {
        $channel = $this->channel(ChannelTypeEnum::WhatsApp, 'ignored');

        $this->assertNull($channel->publicUsername());
        $this->assertNull($channel->publicUrl());
    }

    private function channel(ChannelTypeEnum $type, ?string $username): Channel
    {
        $channel = new Channel();
        $channel->forceFill([
            'type'                  => $type,
            'telegram_bot_username' => $username,
        ]);

        return $channel;
    }
}
