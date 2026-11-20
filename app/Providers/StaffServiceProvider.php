<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Staff\Contracts\ApiTokenAuthenticatorInterface;
use App\Domains\Staff\Listeners\AnnounceStaffNotification;
use App\Domains\Staff\Listeners\CloseSupportAccessEntry;
use App\Domains\Staff\Listeners\NotifyAdminsOfLimit;
use App\Domains\Staff\Listeners\NotifyAdminsOfWorkspaceDeletion;
use App\Domains\Staff\Listeners\RememberUserLocale;
use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\SupportAccessEntry;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\Notifiers\EmailStaffNotifier;
use App\Domains\Staff\Notifications\Notifiers\InAppStaffNotifier;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Staff\Policies\RolePolicy;
use App\Domains\Staff\Policies\StaffGate;
use App\Domains\Staff\Policies\SupportAccessEntryPolicy;
use App\Domains\Staff\Policies\UserPolicy;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Staff\Services\ApiTokenService;
use App\Domains\Staff\Services\CoreOperatorAccess;
use App\Domains\Staff\Services\HashedApiTokenAuthenticator;
use App\Domains\Staff\Services\RoleFormDataMapper;
use App\Domains\Staff\Services\RoleWriterService;
use App\Domains\Staff\Services\TenantAdminDirectory;
use App\Domains\Staff\Services\UserService;
use App\Domains\Staff\Support\ApiTokenGuard;
use App\Domains\Staff\Support\ApiTokenSettings;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantAdminDirectoryInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Events\LimitApproaching;
use App\Domains\Tenancy\Events\LimitReached;
use App\Domains\Tenancy\Events\WorkspaceDeletionCancelled;
use App\Domains\Tenancy\Events\WorkspaceDeletionScheduled;
use App\Domains\Tenancy\Services\PlatformSettingRegistry;
use App\Domains\Tenancy\Services\TenantUsageCounters;
use App\Domains\Tenancy\Support\TenantHost;
use Fapost\Foundation\Operator\Contracts\OperatorAccessInterface;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        // Not a singleton: it records to the audit trail, which is scoped (the actor and the log status of one job).
        $this->app->bind(RoleWriterService::class);
        $this->app->when(RoleWriterService::class)
            ->needs('$guard')
            ->giveConfig('auth.defaults.guard', 'web');
        $this->app->singleton(RoleFormDataMapper::class);
        // Not a singleton: it holds the record quota, which reads the scoped tenant context.
        $this->app->bind(UserService::class);
        $this->app->singleton(StaffRecipientResolver::class);
        // Not a singleton: it reads the current tenant's schema.
        $this->app->bind(TenantAdminDirectoryInterface::class, TenantAdminDirectory::class);

        // The Staff `Gate::before` (StaffGate): the support user's protections, tenant API tokens (fail-closed), the
        // operator screens and the administrators' bypass. Registered the moment the Gate is resolved, from register(),
        // so it runs ahead of every other before-callback, the permission package's included: that one answers `true`
        // for any permission a role holds, the support user's admin role among them.
        $this->callAfterResolving(GateContract::class, static function (GateContract $gate): void {
            $gate->before(static fn (mixed $user, string $ability, array $arguments): ?bool => StaffGate::before($user, $ability, $arguments, CoreOperatorAccess::GATE_ABILITIES));
        });

        // Tenant API tokens: the authenticator reads the tenant of the request.
        $this->app->scoped(ApiTokenAuthenticatorInterface::class, HashedApiTokenAuthenticator::class);
        // Not a singleton: it records to the audit trail, which is scoped.
        $this->app->bind(ApiTokenService::class);

        // Who may open the operator screens. bindIf: an operator layer that already bound its own answer keeps it.
        $this->app->bindIf(OperatorAccessInterface::class, CoreOperatorAccess::class);

        // Staff notification transports — registered into a single in-memory
        // registry. Adding a new channel (e.g. a messenger bot) is a one-line
        // register() call here; the notify_staff node picks it up automatically.
        $this->app->singleton(StaffNotifierRegistry::class, function (): StaffNotifierRegistry {
            $registry = new StaffNotifierRegistry();
            $registry->register(new InAppStaffNotifier());
            // Resolved at the time of sending, inside the job's tenant switch: the registry outlives the job. A mail sent
            // outside a tenant has no host to link to, so it fails (the tenant context throws) instead of linking to the
            // base domain, which does not serve the console.
            $registry->register(new EmailStaffNotifier(static fn (string $path): string => TenantHost::urlFor(app(TenantContextInterface::class)->get(), $path)));

            return $registry;
        });
    }

    /**
     * Register staff authorization policies.
     */
    public function boot(): void
    {
        // The `api-token` guard: a stateless guard whose principal is an ApiToken, found by the authenticator.
        Auth::viaRequest(ApiTokenGuard::DRIVER, static fn (Request $request): ?ApiToken => app(ApiTokenAuthenticatorInterface::class)->authenticate($request));

        // Requests of one token in a minute (`api_tokens.per_minute`), per tenant and token. Reached only after `auth`.
        RateLimiter::for(ApiTokenGuard::LIMITER, static function (Request $request): Limit {
            $token = $request->user(ApiTokenGuard::NAME);

            return Limit::perMinute(max(1, app(PlatformSettingsReaderInterface::class)->integer(ApiTokenSettings::PER_MINUTE)))
                ->by(app(TenantContextInterface::class)->get()->getId() . ':' . ($token instanceof ApiToken ? (string) $token->getKey() : (string) $request->ip()));
        });

        ApiTokenSettings::register($this->app->make(PlatformSettingRegistry::class));

        Event::listen(Logout::class, CloseSupportAccessEntry::class);
        // The bell hears of every in-app notification on the user's own channel.
        Event::listen(NotificationSent::class, AnnounceStaffNotification::class);
        Event::listen(Login::class, RememberUserLocale::class);
        Event::listen(LimitReached::class, NotifyAdminsOfLimit::class);
        Event::listen(LimitApproaching::class, NotifyAdminsOfLimit::class);
        Event::listen(WorkspaceDeletionScheduled::class, NotifyAdminsOfWorkspaceDeletion::class);
        Event::listen(WorkspaceDeletionCancelled::class, NotifyAdminsOfWorkspaceDeletion::class);

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(SupportAccessEntry::class, SupportAccessEntryPolicy::class);

        // Registered the way a Solution registers its keys: from boot(), before the registry is frozen.
        $this->app->make(LimitRegistryInterface::class)->register(new LimitDefinition(
            key: User::LIMIT_KEY,
            label: 'Staff',
            unit: 'staff members',
            kind: LimitKind::Records,
            description: 'How many staff accounts a tenant can have at a time; deactivated accounts and the platform support user do not count, invited and suspended ones do.',
        ));

        $this->app->make(TenantUsageCounters::class)->register(User::LIMIT_KEY, User::countForLimit(...));
    }
}
