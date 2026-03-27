<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
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
    public function register(): void
    {
        $this->app->singleton(AclBootstrapService::class);
        $this->app->singleton(RoleWriterService::class);
        $this->app->singleton(RoleFormDataMapper::class);
        $this->app->singleton(UserService::class);
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
    }
}
