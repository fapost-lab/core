<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

/**
 * In-memory registry of staff notification transports keyed by channel.
 *
 * Populated at boot from {@see \App\Providers\StaffServiceProvider}. The
 * `notify_staff` delivery job looks transports up by channel; an unknown
 * channel resolves to null and is skipped.
 */
final class StaffNotifierRegistry
{
    /**
     * @var array<string, StaffNotifierInterface>
     */
    private array $notifiers = [];

    public function register(StaffNotifierInterface $notifier): void
    {
        $this->notifiers[$notifier->channel()] = $notifier;
    }

    public function get(string $channel): ?StaffNotifierInterface
    {
        return $this->notifiers[$channel] ?? null;
    }

    /**
     * Registered channel identifiers.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys($this->notifiers);
    }
}
