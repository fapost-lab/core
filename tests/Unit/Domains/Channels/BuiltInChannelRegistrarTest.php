<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\BuiltInChannelRegistrar;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Telegram\Media\TelegramMediaLimits;
use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Channels\Telegram\TelegramChannelProfile;
use App\Domains\Channels\Telegram\TelegramSender;
use Fapost\Foundation\Connector\ConnectorChannel;
use LogicException;
use stdClass;
use Tests\Fixtures\Connectors\Echo\EchoChannelProfile;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\TestCase;

final class BuiltInChannelRegistrarTest extends TestCase
{
    private ConnectorChannels $channels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channels = new ConnectorChannels();
    }

    public function test_a_built_in_type_is_registered_with_no_owner(): void
    {
        $channel = new ConnectorChannel(
            type: 'telegram',
            sender: TelegramSender::class,
            inbound: TelegramAdapter::class,
            profile: TelegramChannelProfile::class,
            mediaLimits: TelegramMediaLimits::class,
        );

        $this->registrar()->registerChannel($channel);

        $this->assertSame($channel, $this->channels->find('telegram'));
        $this->assertNull($this->channels->ownerOf('telegram'));
    }

    public function test_a_type_that_is_not_built_in_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Built-in channel module registers channel type "echo", which is not a built-in channel type');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class));
    }

    public function test_a_type_registered_twice_is_refused(): void
    {
        $registrar = $this->registrar();
        $channel   = new ConnectorChannel(type: 'telegram', sender: TelegramSender::class, inbound: TelegramAdapter::class);
        $registrar->registerChannel($channel);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Built-in channel module registers channel type "telegram", which is already registered.');

        $registrar->registerChannel($channel);
    }

    public function test_a_sender_that_is_not_a_sender_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registers sender ' . stdClass::class . ', which must implement Fapost\Foundation\Messaging\ProviderSenderInterface');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'telegram', sender: stdClass::class, inbound: TelegramAdapter::class));
    }

    public function test_a_profile_for_another_channel_type_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('with profile ' . EchoChannelProfile::class . ', whose channelType() is "echo"');

        $this->registrar()->registerChannel(new ConnectorChannel(
            type: 'telegram',
            sender: TelegramSender::class,
            inbound: TelegramAdapter::class,
            profile: EchoChannelProfile::class,
        ));
    }

    public function test_a_refused_channel_is_not_stored(): void
    {
        try {
            $this->registrar()->registerChannel(new ConnectorChannel(type: 'telegram', sender: stdClass::class, inbound: TelegramAdapter::class));
        } catch (LogicException) {
        }

        $this->assertFalse($this->channels->has('telegram'));
    }

    private function registrar(): BuiltInChannelRegistrar
    {
        return new BuiltInChannelRegistrar($this->channels, $this->app);
    }
}
