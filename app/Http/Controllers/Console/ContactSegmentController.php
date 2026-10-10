<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentMatch;
use App\Domains\Contact\Enums\SegmentOperator;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Services\ContactGroupService;
use App\Domains\Contact\Services\ContactSegmentService;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\ContactSegmentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contact segments on the Inertia console: saved rules that pick contacts for a broadcast, with a rule builder.
 *
 * The controller orchestrates: it authorizes, hands the work to {@see ContactSegmentService} and the list to
 * {@see DataTable}. Segments are tenant-level; the assistant in the URL only places the screen inside the console, and
 * a size is counted over every contact of the tenant, not the assistant's. The choices a condition offers (types,
 * operators, how many values) come from {@see SegmentConditionType} as a prop, so the client holds no copy of them.
 */
final class ContactSegmentController extends Controller
{
    public function __construct(
        private readonly ContactSegmentService $segments,
        private readonly ContactGroupService $groups,
        private readonly ContactTagRepositoryInterface $tags,
        private readonly CurrentAssistantInterface $assistant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ContactSegment::class);

        $table = new DataTable(
            sortable: ['name', 'cached_count', 'cached_count_at'],
            searchable: ['name'],
            defaultSort: 'name',
        );

        return Inertia::render('Console/ContactSegments/Index', [
            'table' => $table->respond(
                $request,
                $this->segments->query(),
                function (ContactSegment $segment): array {
                    $key = $segment->getKey();

                    return [
                        'id'              => (string) $key,
                        'name'            => $segment->name,
                        'match'           => $this->matchOf($segment),
                        'conditionsCount' => $this->conditionsCount($segment),
                        'size'            => $segment->cached_count,
                        'countedAt'       => $segment->cached_count_at?->toIso8601String(),
                        'editUrl'         => $this->url('edit', ['record' => $key]),
                        'deleteUrl'       => $this->url('destroy', ['record' => $key]),
                        'countUrl'        => $this->url('count', ['record' => $key]),
                    ];
                },
            ),
            'can' => [
                'create' => Gate::allows('create', ContactSegment::class),
                'update' => Gate::allows('update', new ContactSegment()),
                'delete' => Gate::allows('delete', new ContactSegment()),
            ],
            'urls' => [
                'index'  => $this->url('index'),
                'create' => $this->url('create'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', ContactSegment::class);

        return Inertia::render('Console/ContactSegments/Create', [
            'schema'  => $this->schema(),
            'options' => $this->options(),
            'urls'    => ['index' => $this->url('index'), 'submit' => $this->url('store')],
        ]);
    }

    public function store(ContactSegmentRequest $request): RedirectResponse
    {
        $this->segments->create($request->segmentName(), $request->segmentRules());

        return $this->backToIndex(trans('console.contact_segments.created'));
    }

    /*
     * Route parameters reach an action by position, not by name, so `$tenant` (the assistant in the URL, already
     * resolved by the console stack) has to be declared ahead of `$record`.
     */
    public function edit(string $tenant, string $record): Response
    {
        $segment = $this->segments->findForTenant($record);

        Gate::authorize('update', $segment);

        return Inertia::render('Console/ContactSegments/Edit', [
            'segment'   => ['id' => (string) $segment->getKey(), 'name' => $segment->name, ...$this->segments->formRules($segment)],
            'size'      => $segment->cached_count,
            'countedAt' => $segment->cached_count_at?->toIso8601String(),
            'schema'    => $this->schema(),
            'options'   => $this->options(),
            'urls'      => ['index' => $this->url('index'), 'submit' => $this->url('update', ['record' => $segment->getKey()])],
        ]);
    }

    public function update(ContactSegmentRequest $request, string $tenant, string $record): RedirectResponse
    {
        $this->segments->update($this->segments->findForTenant($record), $request->segmentName(), $request->segmentRules());

        return $this->backToIndex(trans('console.contact_segments.updated'));
    }

    public function destroy(string $tenant, string $record): RedirectResponse
    {
        $segment = $this->segments->findForTenant($record);

        Gate::authorize('delete', $segment);

        $this->segments->delete($segment);

        return $this->backToList(trans('console.contact_segments.deleted'));
    }

    /**
     * Recounts over all contacts of the tenant and flashes the number. Counting writes the snapshot, so it asks for
     * `update`, as Filament's row action did.
     */
    public function refreshCount(string $tenant, string $record): RedirectResponse
    {
        $segment = $this->segments->findForTenant($record);

        Gate::authorize('update', $segment);

        $count = $this->segments->refreshCount($segment);

        return $this->backToList(trans_choice('console.contact_segments.counted', $count, ['count' => $count]));
    }

    /**
     * The choices of the rule builder, from the domain: the combinators and, per condition type, its operators and
     * how many values each takes. Labels are the ones Filament shows.
     *
     * @return array{
     *     match: list<array{value: string, label: string}>,
     *     types: list<array{value: string, label: string, needsKey: bool, operators: list<array{value: string, label: string, arity: string}>}>
     * }
     */
    private function schema(): array
    {
        return [
            'match' => array_map(
                static fn (SegmentMatch $match): array => ['value' => $match->value, 'label' => trans('segment.match.' . $match->value)],
                SegmentMatch::cases(),
            ),
            'types' => array_map(
                static fn (SegmentConditionType $type): array => [
                    'value'     => $type->value,
                    'label'     => trans('segment.condition.types.' . $type->value),
                    'needsKey'  => $type->needsKey(),
                    'operators' => array_map(
                        static fn (SegmentOperator $operator): array => [
                            'value' => $operator->value,
                            'label' => trans('segment.operators.' . $operator->value),
                            'arity' => $type->arity($operator)->value,
                        ],
                        $type->operators(),
                    ),
                ],
                SegmentConditionType::cases(),
            ),
        ];
    }

    /**
     * The values a condition can pick from or be hinted with. Lists, not maps, so no order is lost on the way.
     *
     * @return array{
     *     platforms: list<array{value: string, label: string}>,
     *     groups: list<array{id: string, name: string}>,
     *     languages: list<string>,
     *     tags: list<string>
     * }
     */
    private function options(): array
    {
        return [
            'platforms' => array_map(
                static fn (PlatformEnum $platform): array => ['value' => $platform->value, 'label' => trans('console.contacts.platforms.' . $platform->value)],
                PlatformEnum::cases(),
            ),
            'groups' => $this->groups->query()->orderBy('name')->get()
                ->map(static fn (ContactGroup $group): array => ['id' => (string) $group->getKey(), 'name' => $group->name])
                ->all(),
            'languages' => $this->segments->languageOptions(),
            'tags'      => $this->tags->distinctTags(),
        ];
    }

    private function matchOf(ContactSegment $segment): string
    {
        $match = $segment->rules['match'] ?? null;

        return SegmentMatch::Any->value === $match ? SegmentMatch::Any->value : SegmentMatch::All->value;
    }

    private function conditionsCount(ContactSegment $segment): int
    {
        $conditions = $segment->rules['conditions'] ?? [];

        return is_array($conditions) ? count($conditions) : 0;
    }

    /**
     * After a form: the list, as it opens by default. The message is Inertia flash data, so it reaches the toast once
     * and is not kept in the browser's history.
     */
    private function backToIndex(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return redirect()->to($this->url('index'));
    }

    /**
     * After an action in the list: the list the user was on, with its search, sort and page (the referer), or the
     * default list when there is none.
     */
    private function backToList(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return redirect()->back(fallback: $this->url('index'));
    }

    /**
     * A relative URL of one of this screen's routes, inside the current assistant's console.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = match ($action) {
            'index', 'create', 'edit' => 'filament.assistant.resources.contact-segments.' . $action,
            default                   => 'console.contact-segments.' . $action,
        };

        return route($name, ['tenant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
