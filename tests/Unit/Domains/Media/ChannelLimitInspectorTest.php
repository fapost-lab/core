<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Channels\Telegram\Media\TelegramMediaLimits;
use App\Domains\Media\Services\ChannelLimitInspector;
use Fapost\Foundation\Media\Enums\MediaKind;
use Tests\Fixtures\Connectors\Echo\EchoChannelMediaLimits;
use Tests\TestCase;

/**
 * The limits come from what each channel integration declared and tagged `media.channel.limits`, not from a Core config.
 */
final class ChannelLimitInspectorTest extends TestCase
{
    public function test_it_warns_for_every_channel_whose_limit_the_file_exceeds(): void
    {
        $inspector = new ChannelLimitInspector([new TelegramMediaLimits(), new EchoChannelMediaLimits()]);

        $this->assertSame([
            ['channel_type' => 'telegram', 'kind' => 'image', 'limit_bytes' => 10 * 1024 * 1024, 'actual_bytes' => 11 * 1024 * 1024],
            ['channel_type' => 'echo', 'kind' => 'image', 'limit_bytes' => 1024, 'actual_bytes' => 11 * 1024 * 1024],
        ], $inspector->evaluate(MediaKind::Image, 11 * 1024 * 1024));
    }

    public function test_a_file_within_the_limit_or_a_kind_without_one_gives_no_warning(): void
    {
        $inspector = new ChannelLimitInspector([new TelegramMediaLimits(), new EchoChannelMediaLimits()]);

        $this->assertSame([], $inspector->evaluate(MediaKind::Image, 1024));
        $this->assertSame([], $inspector->evaluate(MediaKind::Video, 50 * 1024 * 1024));
        $this->assertSame([], (new ChannelLimitInspector())->evaluate(MediaKind::Video, PHP_INT_MAX));
    }

    public function test_telegram_declares_its_bot_api_caps(): void
    {
        $limits = new TelegramMediaLimits();

        $this->assertSame('telegram', $limits->channelType());
        $this->assertSame(10 * 1024 * 1024, $limits->maxBytes(MediaKind::Image));
        $this->assertSame(512 * 1024, $limits->maxBytes(MediaKind::Sticker));

        foreach ([MediaKind::Video, MediaKind::Audio, MediaKind::Document, MediaKind::Other] as $kind) {
            $this->assertSame(50 * 1024 * 1024, $limits->maxBytes($kind));
        }
    }

    public function test_the_container_builds_it_from_the_tagged_limits_of_the_integrations(): void
    {
        $types = array_map(
            static fn (array $warning): string => $warning['channel_type'],
            $this->app->make(ChannelLimitInspector::class)->evaluate(MediaKind::Sticker, 600 * 1024),
        );

        $this->assertSame(['telegram'], $types);
    }
}
