<?php

declare(strict_types=1);

namespace App\Domains\Staff\Notifications;

use App\Domains\Staff\Models\User;

/**
 * A single delivery transport for staff notifications (in-app, email, …).
 *
 * Transports are registered in {@see StaffNotifierRegistry} and selected by the
 * `notify_staff` node through {@see \App\Domains\Staff\Enums\StaffNotifyChannel}.
 * Keeping each channel behind this contract lets new transports (e.g. a
 * messenger bot, once a staff↔messenger identity exists) be added without
 * changing the node handler or job.
 */
interface StaffNotifierInterface
{
    /**
     * Channel identifier, matching a {@see \App\Domains\Staff\Enums\StaffNotifyChannel} value.
     */
    public function channel(): string;

    /**
     * Whether this transport can currently deliver to the given user (e.g. the
     * user has a verified messenger link). Unavailable transports are skipped.
     */
    public function isAvailableFor(User $user): bool;

    /**
     * Deliver the already-rendered notification text to the user.
     */
    public function send(User $user, string $message): void;
}
