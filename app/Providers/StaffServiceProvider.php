<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\Notifiers\EmailStaffNotifier;
use App\Domains\Staff\Notifications\Notifiers\InAppStaffNotifier;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Staff\Policies\RolePolicy;
use App\Domains\Staff\Policies\UserPolicy;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Staff\Services\RoleFormDataMapper;
use App\Domains\Staff\Services\RoleWriterService;
use App\Domains\Staff\Services\UserService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Staff bounded context: ACL, policies, panel user rules.
 */
final class StaffServiceProvider extends ServiceProvider
{
    /**
     * Register staff bounded-context services.
     */
    public function register(): void
    {
        $this->app->singleton(AclBootstrapService::class);
        $this->app->singleton(RoleWriterService::class);
        $this->app->when(RoleWriterService::class)
            ->needs('$guard')
            ->giveConfig('auth.defaults.guard', 'web');
        $this->app->singleton(RoleFormDataMapper::class);
        $this->app->singleton(UserService::class);
        $this->app->singleton(StaffRecipientResolver::class);

        // Staff notification transports — registered into a single in-memory
        // registry. Adding a new channel (e.g. a messenger bot) is a one-line
        // register() call here; the notify_staff node picks it up automatically.
        $this->app->singleton(StaffNotifierRegistry::class, function (): StaffNotifierRegistry {
            $registry = new StaffNotifierRegistry();
            $registry->register(new InAppStaffNotifier());
            $registry->register(new EmailStaffNotifier());

            return $registry;
        });
    }

    /**
     * Register staff authorization policies.
     */
    public function boot(): void
    {
        // Admin role bypasses all permission checks — no need to re-seed when new permissions are added.
        // Users of other guards (e.g. an operator package's own accounts) reach the Gate too:
        // only Core's staff admins short-circuit it; everyone else goes through their policies.
        Gate::before(static fn (mixed $user): ?bool => $user instanceof User && $user->isAdmin() ? true : null);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }
}
