<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentMatch;
use App\Domains\Contact\Enums\SegmentOperator;
use App\Domains\Contact\Enums\SegmentValueArity;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Services\AssistantContactService;
use App\Domains\Contact\Services\ContactSegmentResolver;
use App\Domains\Contact\Services\ContactSegmentService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The fields of a contact segment, for creating one (no `record` in the route) and for changing one.
 *
 * The shape is checked by {@see rules()}; what a condition may say is decided by {@see SegmentConditionType}, the same
 * source the console form offers its choices from and the resolver reads a condition by, so the pair of type and
 * operator, and the number of values, are checked in {@see after()}. Errors are keyed by the field that is wrong
 * (`conditions.2.value`) so the form can show them under that row.
 */
final class ContactSegmentRequest extends FormRequest
{
    /** A segment this long is not a hand-made audience, and each condition is a query. */
    public const int MAX_CONDITIONS = 50;

    public const int MAX_VALUES = 100;

    /**
     * Changing needs `update` on the segment in the route (a segment of another tenant is a 404), creating needs `create`.
     */
    public function authorize(ContactSegmentService $segments): bool
    {
        $user = $this->user();

        if (null === $user) {
            return false;
        }

        if (null === $this->route('record')) {
            return $user->can('create', ContactSegment::class);
        }

        return $user->can('update', $segments->findForTenant((string) $this->route('record')));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // The table has no unique index on the name and Filament allows repeats, so the console does too.
            'name'                  => ['required', 'string', 'max:255'],
            'match'                 => ['required', Rule::enum(SegmentMatch::class)],
            'conditions'            => ['present', 'array', 'max:' . self::MAX_CONDITIONS],
            'conditions.*'          => ['array'],
            'conditions.*.type'     => ['required', Rule::enum(SegmentConditionType::class)],
            'conditions.*.operator' => ['required', 'string', 'max:20'],
            'conditions.*.key'      => ['nullable', 'string', 'max:255'],
            'conditions.*.value'    => ['nullable', 'array', 'max:' . self::MAX_VALUES],
            'conditions.*.value.*'  => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name'  => mb_strtolower(__('segment.fields.name')),
            'match' => mb_strtolower(__('segment.fields.match')),
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<array<string, mixed>> $conditions */
            $conditions = array_values((array) $this->input('conditions', []));

            $unchecked = $this->existingGroups($conditions);

            foreach ($conditions as $index => $condition) {
                $this->checkCondition($validator, $index, $condition, $unchecked);
            }
        }];
    }

    public function segmentName(): string
    {
        return mb_trim((string) $this->validated('name'));
    }

    /**
     * @return array{match: string, conditions: list<array<string, mixed>>}
     */
    public function segmentRules(): array
    {
        /** @var list<array<string, mixed>> $conditions */
        $conditions = array_values((array) $this->validated('conditions'));

        return ['match' => (string) $this->validated('match'), 'conditions' => $conditions];
    }

    /**
     * @param  array<string, mixed>   $condition
     * @param  array<string, true>    $existingGroups  ids of the tenant's groups that are referenced
     */
    private function checkCondition(Validator $validator, int $index, array $condition, array $existingGroups): void
    {
        $type     = SegmentConditionType::from((string) $condition['type']);
        $operator = SegmentOperator::tryFrom((string) $condition['operator']);
        $prefix   = "conditions.{$index}";

        if (null === $operator || ! in_array($operator, $type->operators(), true)) {
            $validator->errors()->add("{$prefix}.operator", __('segment.validation.operator_mismatch'));

            return;
        }

        if ($type->needsKey()) {
            $key = mb_trim((string) ($condition['key'] ?? ''));

            if ('' === $key) {
                $validator->errors()->add("{$prefix}.key", __('segment.validation.key_required'));
            } elseif (1 !== preg_match(ContactSegmentResolver::ATTRIBUTE_KEY_PATTERN, $key)) {
                $validator->errors()->add("{$prefix}.key", __('segment.validation.key_invalid'));
            }
        }

        $arity = $type->arity($operator);

        if (SegmentValueArity::None === $arity) {
            return;
        }

        $values = $this->cleanValues($condition['value'] ?? []);

        if (SegmentValueArity::One === $arity && 1 !== count($values)) {
            $validator->errors()->add("{$prefix}.value", __([] === $values ? 'segment.validation.value_required' : 'segment.validation.one_value'));

            return;
        }

        if ([] === $values) {
            $validator->errors()->add("{$prefix}.value", __('segment.validation.value_required'));

            return;
        }

        $this->checkValues($validator, "{$prefix}.value", $type, $values, $existingGroups);
    }

    /**
     * @param  list<string>         $values
     * @param  array<string, true>  $existingGroups
     */
    private function checkValues(Validator $validator, string $field, SegmentConditionType $type, array $values, array $existingGroups): void
    {
        foreach ($values as $value) {
            $message = match ($type) {
                SegmentConditionType::Language => 1 === preg_match(AssistantContactService::LANGUAGE_PATTERN, $value) ? null : 'segment.validation.language',
                SegmentConditionType::Platform => null === PlatformEnum::tryFrom($value) ? 'segment.validation.platform' : null,
                SegmentConditionType::Group    => isset($existingGroups[$value]) ? null : 'segment.validation.group_missing',
                default                        => null,
            };

            if (null !== $message) {
                $validator->errors()->add($field, __($message));

                return;
            }
        }
    }

    /**
     * Which of the group ids the conditions name are groups of the current tenant, with one query for all of them.
     *
     * @param  list<array<string, mixed>>  $conditions
     *
     * @return array<string, true>
     */
    private function existingGroups(array $conditions): array
    {
        $ids = [];

        foreach ($conditions as $condition) {
            if (SegmentConditionType::Group->value !== $condition['type']) {
                continue;
            }

            foreach ($this->cleanValues($condition['value'] ?? []) as $value) {
                if (Str::isUuid($value)) {
                    $ids[$value] = $value;
                }
            }
        }

        if ([] === $ids) {
            return [];
        }

        $found = ContactGroup::query()
            ->where('tenant_id', app(TenantContextInterface::class)->get()->getId())
            ->whereIn('id', array_values($ids))
            ->pluck('id')
            ->all();

        return array_fill_keys(array_map('strval', $found), true);
    }

    /**
     * @return list<string>
     */
    private function cleanValues(mixed $values): array
    {
        $clean = [];

        foreach (is_array($values) ? $values : [] as $value) {
            $value = is_string($value) ? mb_trim($value) : '';

            if ('' !== $value && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return $clean;
    }
}
