<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Enums\AuditArea;
use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Audit\Services\ActivityLogQuery;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's activity log in the admin panel: who changed what in the console and the builder, newest first. Read
 * only — entries are written by Core through the audit trail ({@see ActivityLogQuery} reads them).
 *
 * The window is the last 30 days unless the request names another; `all` lifts it. The rows carry only what the screen
 * shows, and what a person reads (an action's name, an area's name) is translated here from `lang/{locale}/audit.php`,
 * an action the catalogue no longer knows showing as its key. Keys whose value the audit trail never records are dropped
 * once more from values and properties on the way out.
 */
final class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogQuery $log,
        private readonly TenantContextInterface $tenants,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ActivityEntry::class);

        $this->defaultPeriod($request);

        $table = new DataTable(
            sortable: ['created_at'],
            searchable: ['actor_label', 'subject_label'],
            defaultSort: '-created_at',
            filters: [
                'area'      => fn (Builder $query, string $value): bool => $this->log->filterByArea($query, $value),
                'actor'     => fn (Builder $query, string $value): bool => $this->log->filterByActor($query, $value),
                'assistant' => fn (Builder $query, string $value): bool => $this->log->filterByAssistant($query, $value),
                'period'    => fn (Builder $query, string $value): bool => $this->log->filterByPeriod($query, $value),
            ],
        );

        $page = $table->respond($request, $this->log->query(), fn (ActivityEntry $entry): array => $this->row($entry));

        return Inertia::render('Console/ActivityLog/Index', [
            'table'   => $this->withAssistantNames($page),
            'filters' => [
                'areas' => array_map(
                    static fn (AuditArea $area): array => ['key' => $area->value, 'label' => __('audit.areas.' . $area->value)],
                    AuditArea::cases(),
                ),
                'actors' => User::query()
                    ->withoutPlatformSupport()
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(static fn (User $user): array => ['id' => (string) $user->getKey(), 'name' => $user->name])
                    ->all(),
                'assistants' => $this->assistants()
                    ->get(['id', 'name'])
                    ->map(static fn (Assistant $assistant): array => ['id' => (string) $assistant->getKey(), 'name' => $assistant->name])
                    ->all(),
                'periods' => ActivityLogQuery::PERIODS,
            ],
            'urls' => [
                'index'         => route('console.admin.activity.index', [], false),
                'supportAccess' => route('console.admin.support-access.index', [], false),
            ],
        ]);
    }

    /**
     * A request that names no usable period gets the default one, so the query is narrowed and the page shows it selected.
     */
    private function defaultPeriod(Request $request): void
    {
        $filters = $request->query('filter');
        $filters = is_array($filters) ? $filters : [];
        $period  = $filters['period'] ?? null;

        if (! is_string($period) || ! in_array(mb_trim($period), ActivityLogQuery::PERIODS, true)) {
            $filters['period'] = ActivityLogQuery::DEFAULT_PERIOD;
            $request->query->set('filter', $filters);
        }
    }

    /**
     * @return Builder<Assistant>
     */
    private function assistants(): Builder
    {
        return Assistant::query()->where('tenant_id', $this->tenants->get()->getId())->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ActivityEntry $entry): array
    {
        $changes    = $entry->attribute_changes?->toArray() ?? [];
        $properties = $entry->properties?->toArray() ?? [];
        $hidden     = array_flip(array_filter((array) config('activitylog.default_except_attributes', []), is_string(...)));

        $new = array_diff_key(is_array($changes['attributes'] ?? null) ? $changes['attributes'] : [], $hidden);
        $old = array_diff_key(is_array($changes['old'] ?? null) ? $changes['old'] : [], $hidden);
        $key = (string) $entry->description;

        $supportEntryId = $properties['support_entry_id'] ?? null;

        $values = [];

        foreach ($new as $field => $value) {
            $values[] = ['field' => (string) $field, 'old' => $this->display($old[$field] ?? null), 'new' => $this->display($value)];
        }

        // A marker is a field name without its value (`config`, `password` changed), so it is shown even for a key
        // whose value is never recorded.
        $markers = array_values(array_map(strval(...), is_array($changes['changed'] ?? null) ? $changes['changed'] : []));

        $list = [];

        foreach (Arr::except(array_diff_key($properties, $hidden), ['support_entry_id']) as $name => $value) {
            $list[] = ['key' => (string) $name, 'value' => $this->display($value) ?? ''];
        }

        return [
            'id'             => (string) $entry->getKey(),
            'createdAt'      => $entry->created_at->toIso8601String(),
            'actorKind'      => $entry->actor_kind->value,
            'actorLabel'     => $entry->actor_label,
            'action'         => $key,
            'actionLabel'    => $this->label('audit.actions.' . $key, $key),
            'area'           => (string) $entry->log_name,
            'areaLabel'      => $this->label('audit.areas.' . $entry->log_name, (string) $entry->log_name),
            'subjectLabel'   => $entry->subject_label,
            'assistantId'    => $entry->assistant_id,
            'assistantName'  => null,
            'values'         => $values,
            'markers'        => $markers,
            'properties'     => $list,
            'supportEntryId' => is_scalar($supportEntryId) ? (string) $supportEntryId : null,
        ];
    }

    /**
     * Fills in the assistants' names for the rows of the page, one query for all of them.
     *
     * @param  array{rows: list<array<string, mixed>>}&array<string, mixed>  $page
     *
     * @return array<string, mixed>
     */
    private function withAssistantNames(array $page): array
    {
        $ids = array_values(array_unique(array_filter(array_column($page['rows'], 'assistantId'), is_string(...))));

        $names = [] === $ids
            ? []
            : $this->assistants()->whereIn('id', $ids)->pluck('name', 'id')->all();

        $page['rows'] = array_map(static function (array $row) use ($names): array {
            $row['assistantName'] = is_string($row['assistantId']) ? ($names[$row['assistantId']] ?? null) : null;

            return $row;
        }, $page['rows']);

        return $page;
    }

    /**
     * The translation of a key, or `$fallback` when the language files have none.
     */
    private function label(string $translationKey, string $fallback): string
    {
        $text = __($translationKey);

        return $text === $translationKey ? $fallback : $text;
    }

    /**
     * A recorded value as text: `null` stays empty, a flag is "true" or "false", a list or a map is JSON.
     */
    private function display(mixed $value): ?string
    {
        return match (true) {
            null === $value   => null,
            is_bool($value)   => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default           => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
