<?php

declare(strict_types=1);

namespace App\Domains\Channels;

use Fapost\Foundation\Connector\ConnectorChannel;
use LogicException;

/**
 * The channel types connectors registered at boot, with the connector that owns each.
 *
 * Filled once per process, before the first request or job, by the connector registrar in Infrastructure; the
 * {@see ChannelRegistry} reads it next to the tagged built-in definitions. It holds class names only, so it depends on
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
     * @param  string|null  $connectorName  the connector's display name from its manifest, shown for the type
     */
    public function add(ConnectorChannel $channel, string $connectorId, ?string $connectorName = null): void
    {
        if ($this->frozen) {
            throw new LogicException('Connector channels are registered at boot only.');
        }

        $this->channels[$channel->type] = $channel;
        $this->owners[$channel->type]   = $connectorId;

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
     * Id of the connector that registered the type.
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
