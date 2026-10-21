<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\ChannelRegistration;
use App\Domains\Channels\ConnectorChannels;
use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Connector\ConnectorChannel;
use LogicException;
use stdClass;
use Tests\Fixtures\Connectors\Echo\EchoChannelMediaLimits;
use Tests\Fixtures\Connectors\Echo\EchoChannelProfile;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoMediaLinkPolicy;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\TestCase;

/**
 * The checks every registrar shares. The connector registrar's own test and the built-in one's cover what each adds.
 */
final class ChannelRegistrationTest extends TestCase
{
    private ConnectorChannels $channels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channels = new ConnectorChannels();
    }

    public function test_a_channel_is_stored_with_its_owner_and_its_media_classes_are_tagged(): void
    {
        $channel = new ConnectorChannel(
            type: 'echo',
            sender: EchoSender::class,
            inbound: EchoInboundAdapter::class,
            mediaLinkPolicy: EchoMediaLinkPolicy::class,
            profile: EchoChannelProfile::class,
            mediaLimits: EchoChannelMediaLimits::class,
        );

        $this->registration()->register($channel, 'echo', 'Echo');

        $this->assertSame($channel, $this->channels->find('echo'));
        $this->assertSame('echo', $this->channels->ownerOf('echo'));
        $this->assertSame('Echo', $this->channels->nameOf('echo'));
        $this->assertCount(1, iterator_to_array($this->app->tagged(ChannelRegistration::TAG_MEDIA_LINK_POLICY), false));

        $limits = array_filter(
            iterator_to_array($this->app->tagged(ChannelRegistration::TAG_MEDIA_LIMITS), false),
            static fn (object $service): bool => $service instanceof EchoChannelMediaLimits,
        );

        $this->assertCount(1, $limits);
    }

    public function test_a_channel_without_an_owner_is_stored_with_none(): void
    {
        $this->registration()->register(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class));

        $this->assertTrue($this->channels->has('echo'));
        $this->assertNull($this->channels->ownerOf('echo'));
        $this->assertNull($this->channels->nameOf('echo'));
    }

    public function test_a_violation_names_the_owner(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Test owner registers sender ' . stdClass::class . ', which must implement Fapost\Foundation\Messaging\ProviderSenderInterface.');

        $this->registration()->register(new ConnectorChannel(type: 'echo', sender: stdClass::class, inbound: EchoInboundAdapter::class));
    }

    public function test_a_class_that_does_not_exist_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registers inbound Acme\Missing, which is not a class');

        $this->registration()->register(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: 'Acme\\Missing'));
    }

    public function test_a_profile_of_another_type_or_interface_is_refused(): void
    {
        try {
            $this->registration()->register(new ConnectorChannel(type: 'other', sender: EchoSender::class, inbound: EchoInboundAdapter::class, profile: EchoChannelProfile::class));
            $this->fail('A profile for another channel type must be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('with profile ' . EchoChannelProfile::class . ', whose channelType() is "echo"', $exception->getMessage());
        }

        $this->expectExceptionMessage('registers profile ' . EchoSender::class . ', which must implement ' . ChannelProfileInterface::class);

        $this->registration()->register(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class, profile: EchoSender::class));
    }

    public function test_media_limits_of_another_type_are_refused_and_nothing_is_left_behind(): void
    {
        try {
            $this->registration()->register(new ConnectorChannel(
                type: 'other',
                sender: EchoSender::class,
                inbound: EchoInboundAdapter::class,
                mediaLimits: EchoChannelMediaLimits::class,
            ));
            $this->fail('Media limits for another channel type must be refused.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('with mediaLimits ' . EchoChannelMediaLimits::class . ', whose channelType() is "echo"', $exception->getMessage());
        }

        $this->assertFalse($this->channels->has('other'));
    }

    private function registration(): ChannelRegistration
    {
        return new ChannelRegistration($this->channels, $this->app, 'Test owner');
    }
}
