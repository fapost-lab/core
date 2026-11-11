<?php

declare(strict_types=1);

namespace App\Domains\Audit\Services;

use App\Domains\Audit\Enums\AuditActorKind;
use App\Domains\Audit\Enums\AuditArea;
use App\Domains\Audit\Models\ActivityEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Reads the current tenant's audit log for the screen: the list query and the filters it offers. The table lives in
 * the tenant's schema, so every query here is the current tenant's.
 *
 * Every filter takes the raw `filter[key]` value and answers whether it narrowed the query; a value it does not know
 * is no filter.
 */
final readonly class ActivityLogQuery
{
    /** Period choices of the screen, in days; `all` is none. */
    public const array PERIODS = ['7', '30', '90', 'all'];

    public const string DEFAULT_PERIOD = '30';

    /** The actor filter's values besides a staff user's id. */
    public const string ACTOR_SUPPORT = 'support';

    public const string ACTOR_SYSTEM = 'system';

    /**
     * @return Builder<ActivityEntry>
     */
    public function query(): Builder
    {
        return ActivityEntry::query();
    }

    /**
     * @throws ModelNotFoundException when the id is not an entry of this tenant
     */
    public function find(string $id): ActivityEntry
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(ActivityEntry::class, [$id]);
        }

        return $this->query()->whereKey($id)->firstOrFail();
    }

    /**
     * @param  Builder<ActivityEntry>  $query
     */
    public function filterByArea(Builder $query, string $value): bool
    {
        if (null === AuditArea::tryFrom($value)) {
            return false;
        }

        $query->where('log_name', $value);

        return true;
    }

    /**
     * `support` (any platform operator), `system`, or the id of a staff user.
     *
     * @param  Builder<ActivityEntry>  $query
     */
    public function filterByActor(Builder $query, string $value): bool
    {
        if (self::ACTOR_SUPPORT === $value || self::ACTOR_SYSTEM === $value) {
            $query->where('actor_kind', self::ACTOR_SUPPORT === $value ? AuditActorKind::Support->value : AuditActorKind::System->value);

            return true;
        }

        if (! Str::isUuid($value)) {
            return false;
        }

        $query->where('actor_kind', AuditActorKind::Staff->value)->where('causer_id', mb_strtolower($value));

        return true;
    }

    /**
     * @param  Builder<ActivityEntry>  $query
     */
    public function filterByAssistant(Builder $query, string $value): bool
    {
        if (! Str::isUuid($value)) {
            return false;
        }

        $query->where('assistant_id', mb_strtolower($value));

        return true;
    }

    /**
     * The last 7, 30 or 90 days, or `all`.
     *
     * @param  Builder<ActivityEntry>  $query
     */
    public function filterByPeriod(Builder $query, string $value, ?CarbonImmutable $now = null): bool
    {
        if (! in_array($value, self::PERIODS, true)) {
            return false;
        }

        if ('all' !== $value) {
            $query->where('created_at', '>=', ($now ?? CarbonImmutable::now())->subDays((int) $value));
        }

        return true;
    }
}
