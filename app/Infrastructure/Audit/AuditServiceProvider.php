<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domains\Audit\Contracts\AuditActorResolverInterface;
use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Audit\Services\ActivityLogPruner;
use App\Domains\Audit\Services\ActivityLogQuery;
use App\Domains\Audit\Services\AuditRetention;
use App\Domains\Audit\Services\AuditTrail;
use App\Domains\Tenancy\Events\WorkspaceDeletionCancelled;
use App\Domains\Tenancy\Events\WorkspaceDeletionScheduled;
use App\Domains\Tenancy\Services\PlatformSettingRegistry;
use App\Domains\Tenancy\ValueObjects\PlatformSettingField;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Support\ActivityLogStatus;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Wires the audit log: the trail and the actor resolver (both `scoped`, so no job inherits another's actor), the
 * screen's policy, the sign-in and workspace-deletion listeners, and the retention platform setting.
 *
 * Here and not in the Audit domain, which depends on no domain but the Tenancy contracts: the resolver knows Staff and
 * the setting is registered through Tenancy's registry.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuditActorResolverInterface::class, AuditActorResolver::class);

        $this->app->scoped(AuditTrailInterface::class, static fn (Application $app): AuditTrail => new AuditTrail(
            config: $app->make(Repository::class),
            status: $app->make(ActivityLogStatus::class),
            causers: $app->make(CauserResolver::class),
            actors: $app->make(AuditActorResolverInterface::class),
            neverRecorded: array_values(array_filter(
                (array) $app->make(Repository::class)->get('activitylog.default_except_attributes', []),
                is_string(...),
            )),
        ));

        $this->app->scoped(ActivityLogQuery::class);
        $this->app->scoped(ActivityLogPruner::class);
    }

    public function boot(): void
    {
        Gate::policy(ActivityEntry::class, ActivityEntryPolicy::class);

        Event::listen(Login::class, RecordStaffSignIn::class);
        Event::listen(Logout::class, RecordStaffSignIn::class);
        Event::listen(WorkspaceDeletionScheduled::class, RecordWorkspaceDeletion::class);
        Event::listen(WorkspaceDeletionCancelled::class, RecordWorkspaceDeletion::class);

        $this->app->make(PlatformSettingRegistry::class)->register(new PlatformSettingField(
            key: AuditRetention::SETTING,
            type: PlatformSettingType::Integer,
            configKey: 'audit.retention_days',
            fallback: AuditRetention::DEFAULT_DAYS,
            label: 'Audit log retention',
            description: 'How many days an entry of the audit log (what staff did in the console) is kept, for every tenant. The daily audit:prune deletes older entries. 0 keeps them forever.',
            group: 'Audit log',
            unit: 'days',
            min: 0,
            max: AuditRetention::MAX_DAYS,
        ));
    }
}
