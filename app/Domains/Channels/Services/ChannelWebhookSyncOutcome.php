<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

/**
 * The provider refusals of the current request or job, by channel.
 *
 * A delete leaves no row to read a status from, and a deregister keeps none, so the screen that made the change asks
 * this for what the provider refused. Bound as scoped: a queue worker forgets scoped instances between jobs, so
 * nothing recorded here outlives the unit of work that recorded it. Resolve it when it is needed, never hold it in a
 * long-lived object.
 */
final class ChannelWebhookSyncOutcome
{
    /** @var array<string, array{register?: true, deregister?: true}> */
    private array $failures = [];

    public function recordFailure(string $channelId, bool $register): void
    {
        $this->failures[$channelId][$register ? 'register' : 'deregister'] = true;
    }

    public function registerFailed(string $channelId): bool
    {
        return isset($this->failures[$channelId]['register']);
    }

    public function deregisterFailed(string $channelId): bool
    {
        return isset($this->failures[$channelId]['deregister']);
    }

    public function hasFailures(): bool
    {
        return [] !== $this->failures;
    }
}
