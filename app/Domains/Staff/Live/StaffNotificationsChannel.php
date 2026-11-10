<?php

declare(strict_types=1);

namespace App\Domains\Staff\Live;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * The private channel on which a staff user hears that their own notifications changed (one arrived, or was read in
 * another tab). The shell's bell listens to it and reads its count again.
 *
 * Who may listen: only the user it names, on the host of the tenant it names. Decided by comparing ids, never through a
 * policy: an administrator passes every policy through `Gate::before`, and nobody — an administrator included — listens
 * to another user's notifications. The name carries the tenant as {@see \App\Domains\Conversation\Live\ConversationActivityChannel}
 * does, so a websocket server shared by several tenants keeps them apart even before authorization.
 */
final readonly class StaffNotificationsChannel
{
    public const string PATTERN = 'tenant.{tenant}.user.{user}.notifications';

    /** The name the event is broadcast as; Echo listens to it with a leading dot. */
    public const string EVENT = 'staff.notifications';

    public function __construct(
        private TenantContextInterface $tenants,
    ) {
    }

    public static function name(string $tenantId, string $userId): string
    {
        return "tenant.{$tenantId}.user.{$userId}.notifications";
    }

    /**
     * Laravel passes the channel's parameters in the order of {@see self::PATTERN}.
     */
    public function join(User $user, string $tenant, string $userId): bool
    {
        if (! $this->tenants->isResolved() || $this->tenants->get()->getId() !== $tenant) {
            return false;
        }

        return '' !== $userId && (string) $user->getKey() === $userId;
    }
}
