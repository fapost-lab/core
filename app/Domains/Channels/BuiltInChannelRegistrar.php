<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use Fapost\Foundation\Connector\ConnectorChannel;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;
use Illuminate\Contracts\Container\Container;

/**
 * The registrar a built-in channel module registers through: the same contract and the same checks as a connector's,
 * for the channels Core ships.
 *
 * It accepts only a {@see ChannelTypeEnum} type that is not registered yet, the mirror of the connector registrar,
 * which refuses exactly those types. A built-in channel has no owning connector, so
 * {@see ConnectorChannels::ownerOf()} answers null for it. The rest is {@see ChannelRegistration}.
 */
final readonly class BuiltInChannelRegistrar implements ConnectorRegistrarInterface
{
    private ChannelRegistration $registration;

    public function __construct(
        private ConnectorChannels $channels,
        Container $container,
    ) {
        $this->registration = new ChannelRegistration($channels, $container, 'Built-in channel module');
    }

    public function registerChannel(ConnectorChannel $channel): void
    {
        $type = $channel->type;

        if (null === ChannelTypeEnum::tryFrom($type)) {
            $this->registration->fail('registers channel type "%s", which is not a built-in channel type; only a connector package brings an outside channel', $type);
        }

        if ($this->channels->has($type)) {
            $this->registration->fail('registers channel type "%s", which is already registered', $type);
        }

        $this->registration->register($channel);
    }
}
