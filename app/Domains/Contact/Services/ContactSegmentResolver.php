<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentMatch;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactSegment;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Compiles a {@see ContactSegment}'s declarative rules into a tenant-scoped
 * contact query. Rules shape:
 *
 *   { "match": "all"|"any", "conditions": [
 *       { "type": "tag",      "operator": "has"|"not_has",   "value": "vip" },
 *       { "type": "language", "operator": "in"|"eq",         "value": ["en"] },
 *       { "type": "platform", "operator": "in"|"eq",         "value": "telegram" },
 *       { "type": "group",    "operator": "in"|"not_in",     "value": ["<group-id>"] }
 *   ] }
 *
 * A condition we cannot evaluate — unknown type, blank tag, empty group list,
 * malformed attribute key — resolves to nobody rather than being dropped. These
 * rules choose broadcast audiences, so failing open would mean messaging the
 * whole tenant instead of a handful of people.
 *
 * An empty condition set is the separate, deliberate case and still matches
 * every contact of the tenant.
 */
final class ContactSegmentResolver
{
    /**
     * A dot-path of identifier segments; only such keys are allowed into the JSON selector.
     */
    public const string ATTRIBUTE_KEY_PATTERN = '/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/';

    /**
     * @return list<string>
     */
    public function resolveContactIds(ContactSegment $segment): array
    {
        return $this->query($segment)->pluck('id')->all();
    }

    public function count(ContactSegment $segment): int
    {
        return $this->query($segment)->count();
    }

    /**
     * Recompute and persist the denormalized size snapshot.
     */
    public function refreshCount(ContactSegment $segment): int
    {
        $count = $this->count($segment);

        $segment->update([
            'cached_count'    => $count,
            'cached_count_at' => Carbon::now(),
        ]);

        return $count;
    }

    /**
     * @return Builder<\App\Domains\Contact\Models\Contact>
     */
    private function query(ContactSegment $segment): Builder
    {
        /** @var Builder<\App\Domains\Contact\Models\Contact> $query */
        $query = \App\Domains\Contact\Models\Contact::query()
            ->where('tenant_id', $segment->tenant_id);

        $tenantId   = $segment->tenant_id;
        $rules      = $segment->rules;
        $conditions = is_array($rules['conditions'] ?? null) ? $rules['conditions'] : [];
        $isAny      = SegmentMatch::Any->value === ($rules['match'] ?? SegmentMatch::All->value);

        if ([] === $conditions) {
            return $query;
        }

        return $query->where(function (Builder $group) use ($conditions, $isAny, $tenantId): void {
            foreach ($conditions as $condition) {
                if (is_array($condition)) {
                    $this->applyCondition($group, $condition, $isAny, $tenantId);
                }
            }
        });
    }

    /**
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     * @param  array<string, mixed>                          $condition
     */
    private function applyCondition(Builder $query, array $condition, bool $or, string $tenantId): void
    {
        $type     = SegmentConditionType::tryFrom((string) ($condition['type'] ?? ''));
        $operator = (string) ($condition['operator'] ?? '');
        $value    = $condition['value'] ?? null;

        match ($type) {
            SegmentConditionType::Tag       => $this->applyTag($query, $operator, $value, $or),
            SegmentConditionType::Language  => $this->applyColumn($query, 'language', $operator, $value, $or),
            SegmentConditionType::Platform  => $this->applyColumn($query, 'platform', $operator, $value, $or),
            SegmentConditionType::Attribute => $this->applyAttribute($query, $condition, $operator, $value, $or),
            SegmentConditionType::Group     => $this->applyGroup($query, $operator, $value, $or, $tenantId),
            null                            => $this->matchNothing($query, $or),
        };
    }

    /**
     * Constraint for a condition we cannot evaluate — unknown type, blank tag,
     * empty group list, malformed attribute key.
     *
     * Such a condition must narrow to nobody, never silently disappear. These
     * rules pick broadcast audiences: a dropped condition widens the segment,
     * and the failure mode is messaging every contact in the tenant instead of
     * the handful that was intended. Under `any` (OR) this is a no-op, which is
     * the correct reading — an unusable alternative contributes no matches.
     *
     * A segment with no conditions at all is a different, deliberate case and
     * still resolves to every contact — see {@see query()}.
     *
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     */
    private function matchNothing(Builder $query, bool $or): void
    {
        $or ? $query->orWhereRaw('1 = 0') : $query->whereRaw('1 = 0');
    }

    /**
     * Filter on a flow-collected value in the `attributes` json. The key is a
     * dot-path (e.g. `age` or `profile.city`) translated to Laravel's JSON
     * selector. Operators: eq / ne (value comparison), exists (key present).
     *
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     * @param  array<string, mixed>                          $condition
     */
    private function applyAttribute(Builder $query, array $condition, string $operator, mixed $value, bool $or): void
    {
        $key = (string) ($condition['key'] ?? '');

        // Only allow safe dot-path identifiers into the JSON selector.
        if (1 !== preg_match(self::ATTRIBUTE_KEY_PATTERN, $key)) {
            $this->matchNothing($query, $or);

            return;
        }

        $column = 'attributes->' . str_replace('.', '->', $key);

        if ('exists' === $operator) {
            $or ? $query->orWhereNotNull($column) : $query->whereNotNull($column);

            return;
        }

        $scalar = is_array($value) ? ($value[0] ?? null) : $value;

        if (! is_string($scalar) || '' === $scalar) {
            $this->matchNothing($query, $or);

            return;
        }

        $sqlOperator = 'ne' === $operator ? '!=' : '=';

        $or ? $query->orWhere($column, $sqlOperator, $scalar) : $query->where($column, $sqlOperator, $scalar);
    }

    /**
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     */
    private function applyTag(Builder $query, string $operator, mixed $value, bool $or): void
    {
        // Accept both a bare tag and a single-element list (Filament TagsInput).
        $tag = is_array($value) ? ($value[0] ?? null) : $value;

        if (! is_string($tag) || '' === $tag) {
            $this->matchNothing($query, $or);

            return;
        }

        $constraint = static fn (QueryBuilder $q): QueryBuilder => $q->where('tag', $tag);

        if ('not_has' === $operator) {
            $or
                ? $query->orWhereDoesntHave('tags', $constraint)
                : $query->whereDoesntHave('tags', $constraint);

            return;
        }

        $or
            ? $query->orWhereHas('tags', $constraint)
            : $query->whereHas('tags', $constraint);
    }

    /**
     * Filter on membership in one or more {@see ContactGroup}.
     * Value is a list of group ids (Filament Select, multiple).
     *
     * A group that no longer exists (deleted since the segment was saved) makes the whole condition match nobody,
     * under `in` and `not_in` alike: `not_in` of a vanished group would otherwise match every contact, widening a
     * broadcast's audience. The rule is the same as for any condition the resolver cannot evaluate.
     *
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     */
    private function applyGroup(Builder $query, string $operator, mixed $value, bool $or, string $tenantId): void
    {
        $ids = array_values(array_filter(
            is_array($value) ? $value : [$value],
            static fn ($v): bool => is_string($v) && '' !== $v,
        ));

        if ([] === $ids || ! $this->allGroupsExist(array_values(array_unique($ids)), $tenantId)) {
            $this->matchNothing($query, $or);

            return;
        }

        // Qualified: the whereHas subquery joins the pivot, so a bare `id`
        // would be ambiguous the moment that table grows a surrogate key.
        $constraint = static fn (QueryBuilder $q): QueryBuilder => $q->whereIn('contact_groups.id', $ids);

        if ('not_in' === $operator) {
            $or
                ? $query->orWhereDoesntHave('groups', $constraint)
                : $query->whereDoesntHave('groups', $constraint);

            return;
        }

        $or
            ? $query->orWhereHas('groups', $constraint)
            : $query->whereHas('groups', $constraint);
    }

    /**
     * @param  list<string>  $ids
     */
    private function allGroupsExist(array $ids, string $tenantId): bool
    {
        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                return false;
            }
        }

        return ContactGroup::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->count() === count($ids);
    }

    /**
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     */
    private function applyColumn(Builder $query, string $column, string $operator, mixed $value, bool $or): void
    {
        $values = array_values(array_filter(
            is_array($value) ? $value : [$value],
            static fn ($v): bool => is_string($v) && '' !== $v,
        ));

        if ([] === $values) {
            $this->matchNothing($query, $or);

            return;
        }

        if ('eq' === $operator) {
            $or ? $query->orWhere($column, $values[0]) : $query->where($column, $values[0]);

            return;
        }

        $or ? $query->orWhereIn($column, $values) : $query->whereIn($column, $values);
    }
}
