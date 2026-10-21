<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use Fapost\Foundation\Channel\ChannelInboundAdapterInterface;
use Fapost\Foundation\Channel\Settings\ChannelProfileInterface;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Media\ChannelMediaDownloaderInterface;
use Fapost\Foundation\Media\ChannelMediaLimitsInterface;
use Fapost\Foundation\Media\ChannelMediaLinkPolicyInterface;
use Fapost\Foundation\Media\ChannelMediaUploaderInterface;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

/**
 * The checks and the writing every channel registration shares, whoever the registrar is: a connector's or a built-in
 * module's.
 *
 * A registrar decides whether the type may be registered at all (its manifest, whether it is built in, whether it is
 * taken) and hands the channel here. Each class the channel names must exist and implement its Foundation interface;
 * a media class and the profile must answer for the same channel type, and the profile's settings must hold together.
 * Every violation is a {@see LogicException} that names the owner, raised at boot so the process does not start and the
 * problem surfaces on `platform:update` and at deploy, not at a client.
 *
 * The channel goes to {@see ConnectorChannels}, which the {@see ChannelRegistry} reads. The media classes are tagged
 * in the container instead: the media registries read those tags and so know neither connectors nor this namespace.
 */
final readonly class ChannelRegistration
{
    public const string TAG_MEDIA_UPLOADER = 'media.channel.uploader';

    public const string TAG_MEDIA_DOWNLOADER = 'media.channel.downloader';

    public const string TAG_MEDIA_LINK_POLICY = 'media.channel.link';

    public const string TAG_MEDIA_LIMITS = 'media.channel.limits';

    /**
     * @param  string  $owner  names who registers, as the start of every error message
     */
    public function __construct(
        private ConnectorChannels $channels,
        private Container $container,
        private string $owner,
    ) {
    }

    /**
     * Checks the channel's classes, stores it and tags its media classes. Nothing is stored when a check fails.
     *
     * @param  string|null  $ownerId  the connector's id; null for a built-in channel, which no connector owns
     * @param  string|null  $ownerName  the connector's display name from its manifest
     */
    public function register(ConnectorChannel $channel, ?string $ownerId = null, ?string $ownerName = null): void
    {
        $type = $channel->type;

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

        $this->channels->add($channel, $ownerId, $ownerName);

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
     * Raises the registrar's own refusal in the same wording as the shared checks: the owner, then the sentence.
     *
     * @param  string  $message  a sentence continuing "{owner} ", with sprintf placeholders
     */
    public function fail(string $message, string ...$arguments): never
    {
        throw new LogicException(sprintf($this->owner . ' ' . $message . '.', ...$arguments));
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
}
