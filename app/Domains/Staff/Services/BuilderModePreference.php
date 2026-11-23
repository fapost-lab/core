<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\BuilderMode;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use DateTimeImmutable;
use Fapost\Foundation\Analytics\Events\BuilderModeChanged;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Stores the flow builder mode a staff user chose on their own row and announces a switch to the other mode with
 * Foundation's {@see BuilderModeChanged}, so an operator package can follow how the two layouts are used.
 *
 * A user who never chose a mode (`null`) is on the tree, so choosing the tree then, or the mode one already has, is
 * stored but announces nothing. The event carries the tenant and the modes, never the user.
 */
final readonly class BuilderModePreference
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private Dispatcher $events,
    ) {
    }

    public function choose(User $user, BuilderMode $mode): void
    {
        $previous = $user->builder_mode ?? BuilderMode::Tree;

        $user->forceFill(['builder_mode' => $mode])->save();

        if ($previous === $mode) {
            return;
        }

        $this->events->dispatch(new BuilderModeChanged(
            tenantId: $this->tenantContext->get()->getId(),
            mode: $mode->value,
            previousMode: $previous->value,
            occurredAt: new DateTimeImmutable(),
        ));
    }
}
