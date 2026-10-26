<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Fapost\Foundation\Operator\Contracts\OperatorAccessInterface;
use Fapost\Foundation\Operator\Enums\OperatorSurface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;

/**
 * Core's answer to "may this request look at an operator screen".
 *
 * `single`: the signed-in staff member who may use the console and holds
 * {@see Permission::ViewOperatorScreens} (administrators hold every permission through `Gate::before`).
 * The tenant's platform support user is an administrator by role but an operator's proxy, not one of the
 * installation's people, so it is refused before the permission is asked.
 *
 * `host`: nobody. Platform hosts carry no tenant sign-in, and a tenant's staff must never see the whole
 * installation; an operator layer binds its own {@see OperatorAccessInterface} to open the screens to its
 * operators. A surface this class does not know is closed.
 */
final readonly class CoreOperatorAccess implements OperatorAccessInterface
{
    /**
     * Gate abilities of operator screens. The administrators' `Gate::before` bypass skips them: the contract
     * alone decides, so an administrator of a tenant never passes in `host` mode.
     *
     * @var list<string>
     */
    public const array GATE_ABILITIES = ['viewHorizon', 'viewPulse', 'viewTelescope'];

    public function __construct(private Gate $gate)
    {
    }

    public function canView(Request $request, OperatorSurface $surface): bool
    {
        if (TenancyResolutionMode::Single !== TenancyResolutionMode::tryFrom((string) config('tenancy.resolution', 'single'))) {
            return false;
        }

        $known = match ($surface) {
            OperatorSurface::Horizon,
            OperatorSurface::Pulse,
            OperatorSurface::Telescope,
            OperatorSurface::Logs,
            OperatorSurface::Health => true,
            default                 => false,
        };

        $user = $request->user();

        if (! $known || ! $user instanceof User || ! $user->canAccessConsole() || $user->isPlatformSupport()) {
            return false;
        }

        return $this->gate->forUser($user)->allows(Permission::ViewOperatorScreens->value);
    }
}
