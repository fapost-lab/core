<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Solution;

use App\Domains\Channels\ConnectorChannels;
use App\Domains\Media\Registries\ChannelMediaLinkPolicyRegistry;
use App\Domains\Solution\DTO\InstalledConnector;
use App\Infrastructure\Solution\ConnectorChannelRegistrar;
use App\Infrastructure\Solution\ConnectorChannelRegistrarFactory;
use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\Settings\ChannelPublicLink;
use Fapost\Foundation\Channel\Settings\ChannelSettingField;
use Fapost\Foundation\Channel\Settings\ChannelSettingsSchema;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Media\ChannelMediaLinkPolicyInterface;
use LogicException;
use stdClass;
use Tests\Fixtures\Connectors\Echo\EchoChannelMediaLimits;
use Tests\Fixtures\Connectors\Echo\EchoChannelProfile;
use Tests\Fixtures\Connectors\Echo\EchoInboundAdapter;
use Tests\Fixtures\Connectors\Echo\EchoMediaLinkPolicy;
use Tests\Fixtures\Connectors\Echo\EchoSender;
use Tests\TestCase;

final class ConnectorChannelRegistrarTest extends TestCase
{
    private ConnectorChannels $channels;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channels = new ConnectorChannels();
    }

    public function test_a_declared_channel_is_stored_under_its_connector(): void
    {
        $channel = new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class);

        $this->registrar()->registerChannel($channel);

        $this->assertTrue($this->channels->has('echo'));
        $this->assertSame($channel, $this->channels->find('echo'));
        $this->assertSame('echo', $this->channels->ownerOf('echo'));
        $this->assertSame([$channel], $this->channels->all());
    }

    public function test_the_media_link_policy_is_tagged_for_the_media_registry(): void
    {
        $this->registrar()->registerChannel(new ConnectorChannel(
            type: 'echo',
            sender: EchoSender::class,
            inbound: EchoInboundAdapter::class,
            mediaLinkPolicy: EchoMediaLinkPolicy::class,
        ));

        $registry = new ChannelMediaLinkPolicyRegistry($this->app->tagged(ConnectorChannelRegistrar::TAG_MEDIA_LINK_POLICY));

        $this->assertInstanceOf(EchoMediaLinkPolicy::class, $registry->forChannelType('echo'));
    }

    public function test_the_factory_makes_a_registrar_for_the_connector_it_is_given(): void
    {
        $factory = new ConnectorChannelRegistrarFactory($this->channels, $this->app);

        $factory->forConnector($this->connector())->registerChannel(
            new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class),
        );

        $this->assertTrue($this->channels->has('echo'));
    }

    public function test_an_undeclared_type_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Connector "echo" (acme/fapost-connector-echo) registers channel type "other", which extra.fapost.channels does not declare.');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'other', sender: EchoSender::class, inbound: EchoInboundAdapter::class));
    }

    public function test_a_built_in_type_is_refused_even_when_the_manifest_declares_it(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Connector "echo" (acme/fapost-connector-echo) registers channel type "telegram", which Core serves itself');

        $this->registrar(['telegram'])->registerChannel(new ConnectorChannel(type: 'telegram', sender: EchoSender::class, inbound: EchoInboundAdapter::class));
    }

    public function test_whatsapp_is_refused_too(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registers channel type "whatsapp", which Core serves itself');

        $this->registrar(['whatsapp'])->registerChannel(new ConnectorChannel(type: 'whatsapp', sender: EchoSender::class, inbound: EchoInboundAdapter::class));
    }

    public function test_a_type_registered_twice_is_refused(): void
    {
        $registrar = $this->registrar();
        $channel   = new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class);
        $registrar->registerChannel($channel);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registers channel type "echo", which is already registered by connector "echo"');

        $registrar->registerChannel($channel);
    }

    public function test_a_sender_that_is_not_a_sender_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registers sender ' . stdClass::class . ', which must implement Fapost\Foundation\Messaging\ProviderSenderInterface');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'echo', sender: stdClass::class, inbound: EchoInboundAdapter::class));
    }

    public function test_an_inbound_class_of_the_wrong_interface_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registers inbound ' . EchoSender::class . ', which must implement Fapost\Foundation\Channel\ChannelInboundAdapterInterface');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoSender::class));
    }

    public function test_a_class_that_does_not_exist_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registers sender Acme\Missing, which is not a class');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'echo', sender: 'Acme\\Missing', inbound: EchoInboundAdapter::class));
    }

    public function test_a_media_service_for_another_channel_type_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('with mediaLinkPolicy ' . OtherTypeLinkPolicy::class . ', whose channelType() is "other"');

        $this->registrar()->registerChannel(new ConnectorChannel(
            type: 'echo',
            sender: EchoSender::class,
            inbound: EchoInboundAdapter::class,
            mediaLinkPolicy: OtherTypeLinkPolicy::class,
        ));
    }

    public function test_the_profile_and_the_media_limits_are_accepted_and_the_limits_are_tagged(): void
    {
        $this->registrar()->registerChannel(new ConnectorChannel(
            type: 'echo',
            sender: EchoSender::class,
            inbound: EchoInboundAdapter::class,
            profile: EchoChannelProfile::class,
            mediaLimits: EchoChannelMediaLimits::class,
        ));

        $this->assertSame(EchoChannelProfile::class, $this->channels->find('echo')?->profile);

        $tagged = array_filter(
            iterator_to_array($this->app->tagged(ConnectorChannelRegistrar::TAG_MEDIA_LIMITS), false),
            static fn (object $limits): bool => $limits instanceof EchoChannelMediaLimits,
        );

        $this->assertCount(1, $tagged);
    }

    public function test_a_profile_that_is_not_a_profile_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('registers profile ' . EchoSender::class . ', which must implement ' . ChannelProfileInterface::class);

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class, profile: EchoSender::class));
    }

    public function test_a_profile_for_another_channel_type_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('with profile ' . OtherTypeProfile::class . ', whose channelType() is "other"');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class, profile: OtherTypeProfile::class));
    }

    public function test_a_profile_whose_settings_contradict_themselves_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('whose settings are invalid: Channel setting "mode" needs its choices.');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class, profile: BrokenProfile::class));
    }

    public function test_media_limits_for_another_channel_type_are_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('with mediaLimits ' . OtherTypeLimits::class . ', whose channelType() is "other"');

        $this->registrar()->registerChannel(new ConnectorChannel(type: 'echo', sender: EchoSender::class, inbound: EchoInboundAdapter::class, mediaLimits: OtherTypeLimits::class));
    }

    public function test_a_refused_channel_leaves_nothing_behind(): void
    {
        try {
            $this->registrar()->registerChannel(new ConnectorChannel(
                type: 'echo',
                sender: EchoSender::class,
                inbound: EchoInboundAdapter::class,
                mediaLinkPolicy: OtherTypeLinkPolicy::class,
            ));
        } catch (LogicException) {
        }

        $this->assertFalse($this->channels->has('echo'));
        $this->assertSame([], iterator_to_array($this->app->tagged(ConnectorChannelRegistrar::TAG_MEDIA_LINK_POLICY), false));
    }

    /**
     * @param  list<string>  $channels
     */
    private function registrar(array $channels = ['echo']): ConnectorChannelRegistrar
    {
        return new ConnectorChannelRegistrar($this->connector($channels), $this->channels, $this->app);
    }

    /**
     * @param  list<string>  $channels
     */
    private function connector(array $channels = ['echo']): InstalledConnector
    {
        return new InstalledConnector(
            package: 'acme/fapost-connector-echo',
            id: 'echo',
            name: 'Echo',
            description: null,
            provider: 'Acme\\EchoProvider',
            channels: $channels,
            version: '1.0.0',
            composerPath: null,
        );
    }
}

final class OtherTypeLinkPolicy implements ChannelMediaLinkPolicyInterface
{
    public function channelType(): string
    {
        return 'other';
    }

    public function linkTtlSeconds(): ?int
    {
        return null;
    }
}

final class OtherTypeProfile implements ChannelProfileInterface
{
    public function channelType(): string
    {
        return 'other';
    }

    public function settings(): ChannelSettingsSchema
    {
        return new ChannelSettingsSchema();
    }

    public function publicLink(array $config): ?ChannelPublicLink
    {
        return null;
    }
}

final class BrokenProfile implements ChannelProfileInterface
{
    public function channelType(): string
    {
        return 'echo';
    }

    public function settings(): ChannelSettingsSchema
    {
        return new ChannelSettingsSchema([new ChannelSettingField('mode', ChannelSettingType::Choice, 'Mode')]);
    }

    public function publicLink(array $config): ?ChannelPublicLink
    {
        return null;
    }
}

final class OtherTypeLimits implements \Fapost\Foundation\Media\ChannelMediaLimitsInterface
{
    public function channelType(): string
    {
        return 'other';
    }

    public function maxBytes(\Fapost\Foundation\Media\Enums\MediaKind $kind): ?int
    {
        return null;
    }
}
