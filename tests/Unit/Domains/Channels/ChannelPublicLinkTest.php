<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelPublicLinks;
use Tests\TestCase;

/**
 * Public identity of a channel — the bot handle and the deep link staff open
 * from the channels grid and flows template into messages. The integration's profile
 * builds it from the channel's stored config.
 */
final class ChannelPublicLinkTest extends TestCase
{
    public function test_telegram_channel_exposes_handle_and_deep_link(): void
    {
        $link = $this->links()->for($this->channel(ChannelTypeEnum::Telegram, ['bot_username' => 'fapost_demo_bot']));

        $this->assertSame('fapost_demo_bot', $link?->username);
        $this->assertSame('@fapost_demo_bot', $link?->handle);
        $this->assertSame('https://t.me/fapost_demo_bot', $link?->url);
    }

    public function test_telegram_channel_without_registered_bot_has_no_link(): void
    {
        $this->assertNull($this->links()->for($this->channel(ChannelTypeEnum::Telegram, [])));
        $this->assertNull($this->links()->for($this->channel(ChannelTypeEnum::Telegram, ['bot_username' => null])));
    }

    public function test_blank_username_is_treated_as_missing(): void
    {
        $this->assertNull($this->links()->for($this->channel(ChannelTypeEnum::Telegram, ['bot_username' => ''])));
    }

    public function test_whatsapp_channel_has_no_public_link_yet(): void
    {
        $this->assertNull($this->links()->for($this->channel(ChannelTypeEnum::WhatsApp, ['bot_username' => 'ignored'])));
    }

    public function test_a_type_nothing_serves_has_no_link(): void
    {
        $this->assertNull($this->links()->for($this->channel('viber', ['bot_username' => 'x'])));
    }

    private function links(): ChannelPublicLinks
    {
        return $this->app->make(ChannelPublicLinks::class);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function channel(ChannelTypeEnum|string $type, array $config): Channel
    {
        $channel = new Channel();
        $channel->forceFill(['type' => $type, 'config' => $config]);

        return $channel;
    }
}
