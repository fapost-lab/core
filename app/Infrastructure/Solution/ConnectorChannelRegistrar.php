<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Channels\ChannelRegistry;
use App\Domains\Channels\ConnectorChannels;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Solution\DTO\InstalledConnector;
use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Fapost\Foundation\Media\ChannelMediaDownloaderInterface;
use Fapost\Foundation\Media\ChannelMediaLimitsInterface;
use Fapost\Foundation\Media\ChannelMediaLinkPolicyInterface;
use Fapost\Foundation\Media\ChannelMediaUploaderInterface;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

/**
 * The Core side of {@see ConnectorRegistrarInterface} for one connector.
 *
 * Every violation is an exception naming the connector, raised at boot so the process does not start and the problem
 * surfaces on `platform:update` and at deploy, not at a client. A channel is accepted only when its type is declared in
 * the connector's manifest (`extra.fapost.channels`), is not a type Core serves itself, and is not registered yet; each
 * class it names must exist and implement its Foundation interface.
 *
 * A built-in type (any {@see ChannelTypeEnum} case) is refused for good: Telegram and WhatsApp stay in Core on the same
 * contracts a connector uses, so a connector never replaces them.
 *
 * The channel goes to {@see ConnectorChannels}, which the {@see ChannelRegistry} reads. The media classes are tagged
 * in the container instead, the way the built-in channels' are: the media registries read those tags and so know
 * neither connectors nor this namespace.
 */
final readonly class ConnectorChannelRegistrar implements ConnectorRegistrarInterface
{
    public const string TAG_MEDIA_UPLOADER = 'media.channel.uploader';

    public const string TAG_MEDIA_DOWNLOADER = 'media.channel.downloader';

    public const string TAG_MEDIA_LINK_POLICY = 'media.channel.link';

    public const string TAG_MEDIA_LIMITS = 'media.channel.limits';

    public function __construct(
        private InstalledConnector $connector,
        private ConnectorChannels $channels,
        private Container $container,
    ) {
    }

    public function registerChannel(ConnectorChannel $channel): void
    {
        $type = $channel->type;

        if (!in_array($type, $this->connector->channels, true)) {
            $this->fail('registers channel type "%s", which extra.fapost.channels does not declare', $type);
        }

        if (null !== ChannelTypeEnum::tryFrom($type)) {
            $this->fail('registers channel type "%s", which Core serves itself; a connector cannot replace a built-in channel', $type);
        }

        if ($this->channels->has($type)) {
            $this->fail('registers channel type "%s", which is already registered by connector "%s"', $type, (string) $this->channels->ownerOf($type));
        }

        $this->expect($channel->sender, ProviderSenderInterface::class, 'sender');
        $this->expect($channel->inbound, ChannelInboundAdapterInterface::class, 'inbound');

        if (null !== $channel->webhookRegistrar) {
            $this->expect($channel->webhookRegistrar, WebhookRegistrarInterface::class, 'webhookRegistrar');
        }

        $uploader   = $this->mediaService($channel->mediaUploader, ChannelMediaUploaderInterface::class, 'mediaUploader', $type);
        $downloader = $this->mediaService($channel->mediaDownloader, ChannelMediaDownloaderInterface::class, 'mediaDownloader', $type);
        $linkPolicy = $this->mediaService($channel->mediaLinkPolicy, ChannelMediaLinkPolicyInterface::class, 'mediaLinkPolicy', $type);

        $limits = $this->mediaService($channel->mediaLimits, ChannelMediaLimitsInterface::class, 'mediaLimits', $type);

        $this->checkProfile($channel->profile, $type);

        $this->channels->add($channel, $this->connector->id, $this->connector->name);

        foreach ([
            self::TAG_MEDIA_UPLOADER    => $uploader,
            self::TAG_MEDIA_DOWNLOADER  => $downloader,
            self::TAG_MEDIA_LINK_POLICY => $linkPolicy,
            self::TAG_MEDIA_LIMITS      => $limits,
        ] as $tag => $class) {
            if (null !== $class) {
                $this->container->tag($class, $tag);
            }
        }
    }

    /**
     * A media class must exist, implement its interface and answer for the same channel type. The answer is read
     * from one throwaway instance, so the constructor has to be cheap and touch no tenant data.
     *
     * @param  class-string  $interface
     */
    private function mediaService(?string $class, string $interface, string $field, string $type): ?string
    {
        if (null === $class) {
            return null;
        }

        $this->expect($class, $interface, $field);

        /** @var ChannelMediaUploaderInterface|ChannelMediaDownloaderInterface|ChannelMediaLinkPolicyInterface|ChannelMediaLimitsInterface $instance */
        $instance = $this->container->make($class);

        if ($instance->channelType() !== $type) {
            $this->fail('registers channel type "%s" with %s %s, whose channelType() is "%s"', $type, $field, $class, $instance->channelType());
        }

        return $class;
    }

    /**
     * A profile must exist, implement its interface, answer for the same channel type and describe settings that
     * hold together: the schema is built once here, so a contradictory declaration surfaces at boot. Built from one
     * throwaway instance, so the constructor has to be cheap and touch no tenant data.
     */
    private function checkProfile(?string $class, string $type): void
    {
        if (null === $class) {
            return;
        }

        $this->expect($class, ChannelProfileInterface::class, 'profile');

        /** @var ChannelProfileInterface $profile */
        $profile = $this->container->make($class);

        if ($profile->channelType() !== $type) {
            $this->fail('registers channel type "%s" with profile %s, whose channelType() is "%s"', $type, $class, $profile->channelType());
        }

        try {
            $profile->settings();
        } catch (InvalidArgumentException $exception) {
            $this->fail('registers channel type "%s" with profile %s, whose settings are invalid: %s', $type, $class, $exception->getMessage());
        }
    }

    /**
     * @param  class-string  $interface
     */
    private function expect(string $class, string $interface, string $field): void
    {
        if (!class_exists($class)) {
            $this->fail('registers %s %s, which is not a class', $field, $class);
        }

        if (!is_a($class, $interface, true)) {
            $this->fail('registers %s %s, which must implement %s', $field, $class, $interface);
        }
    }

    private function fail(string $message, string ...$arguments): never
    {
        throw new LogicException(sprintf(
            'Connector "%s" (%s) ' . $message . '.',
            $this->connector->id,
            $this->connector->package,
            ...$arguments,
        ));
    }
}
