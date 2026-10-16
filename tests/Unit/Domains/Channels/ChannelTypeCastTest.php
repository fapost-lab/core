<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use Tests\TestCase;

final class ChannelTypeCastTest extends TestCase
{
    public function test_a_built_in_enum_case_is_stored_and_read_as_its_string(): void
    {
        $channel = new Channel(['type' => ChannelTypeEnum::Telegram]);

        $this->assertSame('telegram', $channel->type);
        $this->assertSame('telegram', $channel->getAttributes()['type']);
        $this->assertSame('telegram', $channel->getType());
    }

    public function test_a_type_nothing_serves_still_hydrates(): void
    {
        $channel = (new Channel())->newFromBuilder(['type' => 'viber', 'telegram_bot_username' => 'x']);

        $this->assertSame('viber', $channel->type);
        $this->assertNull($channel->publicUsername());
        $this->assertNull($channel->publicUrl());
    }

    public function test_a_contact_platform_takes_an_enum_case_or_any_string(): void
    {
        $this->assertSame('whatsapp', (new Contact(['platform' => PlatformEnum::WhatsApp]))->platform);
        $this->assertSame('echo', (new Contact(['platform' => 'echo']))->platform);
        $this->assertSame('viber', (new Contact())->newFromBuilder(['platform' => 'viber'])->platform);
    }
}
