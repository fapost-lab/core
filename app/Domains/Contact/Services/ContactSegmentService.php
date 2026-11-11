<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentMatch;
use App\Domains\Contact\Enums\SegmentOperator;
use App\Domains\Contact\Enums\SegmentValueArity;
use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes and reads {@see ContactSegment}s of the current tenant.
 *
 * Segments are tenant-level, so every read and write is bounded by the tenant in {@see TenantContextInterface}: a
 * segment of another tenant is "not found", never "forbidden". Rules are stored in the shape the Filament form wrote
 * (see {@see self::canonicalRules()}), so either UI opens what the other saved. Saving rules does not touch the cached
 * size, as in Filament; a recount is a separate action.
 *
 * Nothing references a segment by a foreign key: a broadcast draft keeps the id of a deleted segment and resolves to
 * nobody ({@see \App\Domains\Broadcasting\Services\BroadcastRecipientResolver}).
 *
 * Each write is recorded in the audit log: the name as a value, the rules as a marker (they name attributes and hold
 * values about people). A segment belongs to the tenant, so its entry has no assistant.
 */
final readonly class ContactSegmentService
{
    public function __construct(
        private TenantContextInterface $tenants,
        private ContactSegmentResolver $resolver,
        private AuditTrailInterface $audit,
    ) {
    }

    /**
     * The segments of the current tenant, for a list to filter, sort and page.
     *
     * @return Builder<ContactSegment>
     */
    public function query(): Builder
    {
        return ContactSegment::query()->where('tenant_id', $this->tenantId());
    }

    /**
     * @throws ModelNotFoundException when the id is not a segment of the current tenant
     */
    public function findForTenant(string $id): ContactSegment
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(ContactSegment::class, [$id]);
        }

        return $this->query()->whereKey($id)->firstOrFail();
    }

    /**
     * @param  array{match: string, conditions: list<array<string, mixed>>}  $rules  validated
     */
    public function create(string $name, array $rules): ContactSegment
    {
        return DB::transaction(function () use ($name, $rules): ContactSegment {
            $segment = ContactSegment::query()->create([
                'tenant_id' => $this->tenantId(),
                'name'      => $name,
                'rules'     => $this->canonicalRules($rules),
            ]);

            $this->record(
                AuditAction::ContactSegmentCreated,
                $segment,
                AuditChanges::created(['name' => $segment->name])->merge(AuditChanges::markers(['rules'])),
            );

            return $segment;
        });
    }

    /**
     * @param  array{match: string, conditions: list<array<string, mixed>>}  $rules  validated
     */
    public function update(ContactSegment $segment, string $name, array $rules): ContactSegment
    {
        return DB::transaction(function () use ($segment, $name, $rules): ContactSegment {
            $segment->update([
                'name'  => $name,
                'rules' => $this->canonicalRules($rules),
            ]);

            $changes = AuditChanges::ofSavedModel($segment, ['name'], ['rules']);

            if (! $changes->isEmpty()) {
                $this->record(AuditAction::ContactSegmentUpdated, $segment, $changes);
            }

            return $segment;
        });
    }

    /**
     * One model at a time, as the convention asks of deletes with consequences (see the class comment on drafts).
     */
    public function delete(ContactSegment $segment): void
    {
        DB::transaction(function () use ($segment): void {
            $segment->delete();

            $this->record(AuditAction::ContactSegmentDeleted, $segment);
        });
    }

    /**
     * Recounts the segment over every contact of the tenant and stores the snapshot.
     */
    public function refreshCount(ContactSegment $segment): int
    {
        return $this->resolver->refreshCount($segment);
    }

    /**
     * Distinct languages of the tenant's contacts, as hints for a language condition.
     *
     * @return list<string>
     */
    public function languageOptions(): array
    {
        /** @var list<string> $languages */
        $languages = Contact::query()
            ->where('tenant_id', $this->tenantId())
            ->whereNotNull('language')
            ->where('language', '!=', '')
            ->distinct()
            ->orderBy('language')
            ->pluck('language')
            ->all();

        return $languages;
    }

    /**
     * The rules in the shape Filament wrote: per condition `{type, operator, value: list<string>}`, with `key` only
     * on an `attribute` and no `value` on `exists`. Strings are trimmed, empty and repeated values dropped with the
     * order kept. The input is already validated.
     *
     * @param  array{match: string, conditions: list<array<string, mixed>>}  $rules
     *
     * @return array{match: string, conditions: list<array<string, mixed>>}
     */
    public function canonicalRules(array $rules): array
    {
        $conditions = [];

        foreach ($rules['conditions'] as $condition) {
            $type     = SegmentConditionType::from((string) $condition['type']);
            $operator = SegmentOperator::from((string) $condition['operator']);
            $stored   = ['type' => $type->value];

            if ($type->needsKey()) {
                $stored['key'] = mb_trim((string) $condition['key']);
            }

            $stored['operator'] = $operator->value;

            if (SegmentValueArity::None !== $type->arity($operator)) {
                $stored['value'] = $this->cleanValues($condition['value'] ?? []);
            }

            $conditions[] = $stored;
        }

        return ['match' => SegmentMatch::from($rules['match'])->value, 'conditions' => $conditions];
    }

    /**
     * The stored rules laid out for the form: every `value` is a list of strings (an older record may hold a bare
     * string). An unknown type or operator is passed on as it is, so the form can say it cannot show it instead of
     * dropping it without a word.
     *
     * @return array{match: string, conditions: list<array{type: string, key: string, operator: string, value: list<string>}>}
     */
    public function formRules(ContactSegment $segment): array
    {
        $rules      = $segment->rules;
        $conditions = [];

        foreach (is_array($rules['conditions'] ?? null) ? $rules['conditions'] : [] as $condition) {
            if (! is_array($condition)) {
                // Kept as a condition nobody can show, so a re-save fails on it instead of erasing it.
                $conditions[] = ['type' => '', 'key' => '', 'operator' => '', 'value' => []];

                continue;
            }

            $value = $condition['value'] ?? [];

            $conditions[] = [
                'type'     => $this->scalar($condition['type'] ?? ''),
                'key'      => $this->scalar($condition['key'] ?? ''),
                'operator' => $this->scalar($condition['operator'] ?? ''),
                'value'    => array_values(array_map($this->scalar(...), is_array($value) ? $value : [$value])),
            ];
        }

        $match = is_string($rules['match'] ?? null) ? $rules['match'] : SegmentMatch::All->value;

        return ['match' => $match, 'conditions' => $conditions];
    }

    /**
     * @return list<string>
     */
    private function cleanValues(mixed $values): array
    {
        $clean = [];

        foreach (is_array($values) ? $values : [$values] as $value) {
            $value = mb_trim($this->scalar($value));

            if ('' !== $value && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return $clean;
    }

    private function record(AuditAction $action, ContactSegment $segment, ?AuditChanges $changes = null): void
    {
        $this->audit->record(new AuditRecord(
            action: $action,
            subject: $segment,
            subjectLabel: $segment->name,
            changes: $changes,
        ));
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
