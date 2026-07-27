<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentMatch;
use App\Domains\Contact\Models\ContactSegment;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Compiles a {@see ContactSegment}'s declarative rules into a tenant-scoped
 * contact query. Rules shape:
 *
 *   { "match": "all"|"any", "conditions": [
 *       { "type": "tag",      "operator": "has"|"not_has", "value": "vip" },
 *       { "type": "language", "operator": "in"|"eq",       "value": ["en"] },
 *       { "type": "platform", "operator": "in"|"eq",       "value": "telegram" }
 *   ] }
 *
 * Unknown / malformed conditions are skipped defensively. An empty condition set
 * matches every contact of the tenant.
 */
final class ContactSegmentResolver
{
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

        $rules      = $segment->rules;
        $conditions = is_array($rules['conditions'] ?? null) ? $rules['conditions'] : [];
        $isAny      = SegmentMatch::Any->value === ($rules['match'] ?? SegmentMatch::All->value);

        if ([] === $conditions) {
            return $query;
        }

        return $query->where(function (Builder $group) use ($conditions, $isAny): void {
            foreach ($conditions as $condition) {
                if (is_array($condition)) {
                    $this->applyCondition($group, $condition, $isAny);
                }
            }
        });
    }

    /**
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     * @param  array<string, mixed>                          $condition
     */
    private function applyCondition(Builder $query, array $condition, bool $or): void
    {
        $type     = SegmentConditionType::tryFrom((string) ($condition['type'] ?? ''));
        $operator = (string) ($condition['operator'] ?? '');
        $value    = $condition['value'] ?? null;

        match ($type) {
            SegmentConditionType::Tag      => $this->applyTag($query, $operator, $value, $or),
            SegmentConditionType::Language => $this->applyColumn($query, 'language', $operator, $value, $or),
            SegmentConditionType::Platform => $this->applyColumn($query, 'platform', $operator, $value, $or),
            null                           => null,
        };
    }

    /**
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     */
    private function applyTag(Builder $query, string $operator, mixed $value, bool $or): void
    {
        // Accept both a bare tag and a single-element list (Filament TagsInput).
        $tag = is_array($value) ? ($value[0] ?? null) : $value;

        if (! is_string($tag) || '' === $tag) {
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
     * @param  Builder<\App\Domains\Contact\Models\Contact>  $query
     */
    private function applyColumn(Builder $query, string $column, string $operator, mixed $value, bool $or): void
    {
        $values = array_values(array_filter(
            is_array($value) ? $value : [$value],
            static fn ($v): bool => is_string($v) && '' !== $v,
        ));

        if ([] === $values) {
            return;
        }

        if ('eq' === $operator) {
            $or ? $query->orWhere($column, $values[0]) : $query->where($column, $values[0]);

            return;
        }

        $or ? $query->orWhereIn($column, $values) : $query->whereIn($column, $values);
    }
}
