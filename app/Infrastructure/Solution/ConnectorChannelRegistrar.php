<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Channels\ChannelRegistration;
use App\Domains\Channels\ChannelRegistry;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Solution\DTO\InstalledConnector;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Illuminate\Contracts\Container\Container;

/**
 * The Core side of {@see ConnectorRegistrarInterface} for one connector.
 *
 * Every violation is an exception naming the connector, raised at boot so the process does not start and the problem
 * surfaces on `platform:update` and at deploy, not at a client. A channel is accepted only when its type is declared in
 * the connector's manifest (`extra.fapost.channels`), is not a type Core serves itself, and is not registered yet; each
 * class it names must exist and implement its Foundation interface.
 *
 * A built-in type (any {@see ChannelTypeEnum} case) is refused for good: Telegram stays in Core on the same
 * contracts a connector uses, so a connector never replaces it.
 *
 * The checks of the channel's classes, its storing in {@see ConnectorChannels} (which the {@see ChannelRegistry} reads)
 * and the media tags are shared with the built-in modules and live in {@see ChannelRegistration}.
 */
final readonly class ConnectorChannelRegistrar implements ConnectorRegistrarInterface
{
    public const string TAG_MEDIA_UPLOADER = ChannelRegistration::TAG_MEDIA_UPLOADER;

    public const string TAG_MEDIA_DOWNLOADER = ChannelRegistration::TAG_MEDIA_DOWNLOADER;

    public const string TAG_MEDIA_LINK_POLICY = ChannelRegistration::TAG_MEDIA_LINK_POLICY;

    public const string TAG_MEDIA_LIMITS = ChannelRegistration::TAG_MEDIA_LIMITS;

    private ChannelRegistration $registration;

    public function __construct(
        private InstalledConnector $connector,
        private ConnectorChannels $channels,
        private Container $container,
    ) {
        $this->registration = new ChannelRegistration(
            $channels,
            $container,
            sprintf('Connector "%s" (%s)', $connector->id, $connector->package),
        );
    }

    public function registerChannel(ConnectorChannel $channel): void
    {
        $type = $channel->type;

        if (!in_array($type, $this->connector->channels, true)) {
            $this->registration->fail('registers channel type "%s", which extra.fapost.channels does not declare', $type);
        }

        if (null !== ChannelTypeEnum::tryFrom($type)) {
            $this->registration->fail('registers channel type "%s", which Core serves itself; a connector cannot replace a built-in channel', $type);
        }

        if ($this->channels->has($type)) {
            $this->registration->fail('registers channel type "%s", which is already registered by connector "%s"', $type, (string) $this->channels->ownerOf($type));
        }

        $this->registration->register($channel, $this->connector->id, $this->connector->name);
    }
}
