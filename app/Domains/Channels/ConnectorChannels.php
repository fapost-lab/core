<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use Fapost\Foundation\Connector\ConnectorChannel;
use LogicException;

/**
 * The channel types registered at boot, with the connector that owns each, if any.
 *
 * Filled once per process, before the first request or job, by a registrar: the connector registrar in
 * Infrastructure, or the built-in one ({@see BuiltInChannelRegistrar}) for the channels Core ships, which no
 * connector owns. The {@see ChannelRegistry} reads every type from here. It holds class names only, so it depends on
 * the image and never on a tenant, and a process may keep it. Core does not check what is added here: the registrar
 * already has.
 */
final class ConnectorChannels
{
    /** @var array<string, ConnectorChannel> */
    private array $channels = [];

    /** @var array<string, string> */
    private array $owners = [];

    /** @var array<string, string> */
    private array $names = [];

    private bool $frozen = false;

    /**
     * @param  string|null  $connectorId  the owning connector; null for a built-in channel
     * @param  string|null  $connectorName  the connector's display name from its manifest, shown for the type
     */
    public function add(ConnectorChannel $channel, ?string $connectorId = null, ?string $connectorName = null): void
    {
        if ($this->frozen) {
            throw new LogicException('Connector channels are registered at boot only.');
        }

        $this->channels[$channel->type] = $channel;

        if (null !== $connectorId) {
            $this->owners[$channel->type] = $connectorId;
        }

        if (null !== $connectorName && '' !== $connectorName) {
            $this->names[$channel->type] = $connectorName;
        }
    }

    /**
     * Closes registration; called once every connector has registered its channels.
     */
    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function has(string $type): bool
    {
        return isset($this->channels[$type]);
    }

    public function find(string $type): ?ConnectorChannel
    {
        return $this->channels[$type] ?? null;
    }

    /**
     * Id of the connector that registered the type; null for a built-in channel or an unknown type.
     */
    public function ownerOf(string $type): ?string
    {
        return $this->owners[$type] ?? null;
    }

    /**
     * Display name of the connector that registered the type, when it gave one.
     */
    public function nameOf(string $type): ?string
    {
        return $this->names[$type] ?? null;
    }

    /**
     * @return list<ConnectorChannel> in registration order
     */
    public function all(): array
    {
        return array_values($this->channels);
    }
}
