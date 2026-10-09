<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Exceptions\FlowHasLiveSessionsException;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Services\FlowDraftService;
use App\Domains\Flow\Services\FlowGroupService;
use App\Domains\Flow\Services\FlowTriggerHint;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\DestroyFlowsRequest;
use App\Http\Requests\Console\FlowRequest;
use App\Http\Requests\Console\UpdateFlowActivityRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Flows on the Inertia console: the list with its filters and grouping, the form for a flow's metadata, switching a flow
 * on and off, and deleting. The graph is the builder's, a separate app: creating a flow ends there, and the list links to it.
 *
 * {@see FlowDraftService} bounds every read and write by the tenant and the assistant in the URL, enforces the flow limit
 * (through its create) and refuses to delete a flow with live sessions; this controller words those refusals as toasts.
 */
final class FlowController extends Controller
{
    public function __construct(
        private readonly FlowDraftService $flows,
        private readonly FlowGroupService $groups,
        private readonly CurrentAssistantInterface $assistant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FlowDraft::class);

        $limit = $this->flows->limit();
        $table = new DataTable(
            sortable: ['name', 'group_name'],
            searchable: ['name'],
            defaultSort: 'name',
            filters: [
                'group'  => fn (Builder $query, string $value): bool => $this->flows->filterByGroup($query, $value),
                'active' => fn (Builder $query, string $value): bool => $this->flows->filterByActive($query, $value),
            ],
            groups: [
                'group' => fn (Builder $query) => $this->flows->orderByGroup($query),
            ],
        );

        return Inertia::render('Console/Flows/Index', [
            'table' => $table->respond(
                $request,
                $this->flows->query(),
                fn (FlowDraft $flow): array => [
                    'id'       => (string) $flow->getKey(),
                    'flowId'   => $flow->flow_id,
                    'name'     => $flow->name,
                    'isActive' => $flow->is_active,
                    'isPublic' => $flow->is_public,
                    // `group_name` is null when the group is gone, and such a flow has no group.
                    'groupId'          => null === $flow->getAttribute('group_name') ? null : $flow->flow_group_id,
                    'groupName'        => $flow->getAttribute('group_name'),
                    'publishedVersion' => null === $flow->getAttribute('published_version') ? null : (int) $flow->getAttribute('published_version'),
                    'trigger'          => FlowTriggerHint::for($flow->trigger),
                    'editUrl'          => $this->url('edit', ['record' => $flow->getKey()]),
                    'deleteUrl'        => $this->url('destroy', ['record' => $flow->getKey()]),
                    'activityUrl'      => $this->url('activity', ['record' => $flow->getKey()]),
                    'builderUrl'       => $this->builderUrl($flow),
                ],
            ),
            'groups' => $this->groups->options(),
            'limit'  => [
                'reached' => $limit->reached,
                'hint'    => $limit->reached && null !== $limit->limit
                    ? trans('assistant.flows.limit.hint', ['current' => $limit->current, 'limit' => $limit->limit])
                    : null,
            ],
            'can' => [
                // The policy lets an administrator through whatever the limit says, so the limit is asked on its own.
                'create' => Gate::allows('create', FlowDraft::class) && ! $limit->reached,
                'update' => Gate::allows('update', $this->flows->abilityProbe()),
                'delete' => Gate::allows('delete', $this->flows->abilityProbe()),
            ],
            'urls' => [
                'index'       => $this->url('index'),
                'create'      => $this->url('create'),
                'destroyMany' => $this->url('destroy-many'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', FlowDraft::class);

        // The page is closed at the limit, but only on opening: a form opened below it that is saved after another
        // request took the last slot still reaches the service, which refuses with a message.
        abort_if($this->flows->limit()->reached, HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('Console/Flows/Create', [
            'groups' => $this->groups->options(),
            'can'    => ['createGroup' => Gate::allows('create', FlowGroup::class)],
            'urls'   => [
                'index'      => $this->url('index'),
                'submit'     => $this->url('store'),
                'storeGroup' => $this->groupUrl('store-inline'),
            ],
        ]);
    }

    public function store(FlowRequest $request): RedirectResponse|HttpResponse
    {
        try {
            $flow = $this->flows->create($request->fields());
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('assistant.flows.limit.reached_title') . '. ' . $exception->getMessage());

            return redirect()->back(fallback: $this->url('index'));
        }

        // The builder is a separate app, so this is a full navigation, not an Inertia visit.
        return Inertia::location($this->builderUrl($flow));
    }

    /*
     * Route parameters reach an action by position, not by name, so `$tenant` (the assistant in the URL, already
     * resolved by the console stack) has to be declared ahead of `$record`.
     */
    public function edit(string $tenant, string $record): Response
    {
        $flow = $this->flows->findForAssistant($record);

        Gate::authorize('update', $flow);

        $groups = $this->groups->options();
        // A group that no longer exists is no group, so saving the form does not trip over it.
        $groupId = in_array($flow->flow_group_id, array_column($groups, 'id'), true) ? $flow->flow_group_id : null;

        return Inertia::render('Console/Flows/Edit', [
            'flow' => [
                'id'             => (string) $flow->getKey(),
                'name'           => $flow->name,
                'flowGroupId'    => $groupId,
                'description'    => $flow->description,
                'isPublic'       => $flow->is_public,
                'loggingEnabled' => $flow->logging_enabled,
            ],
            'groups' => $groups,
            'can'    => ['createGroup' => Gate::allows('create', FlowGroup::class)],
            'urls'   => [
                'index'      => $this->url('index'),
                'submit'     => $this->url('update', ['record' => $flow->getKey()]),
                'storeGroup' => $this->groupUrl('store-inline'),
            ],
        ]);
    }

    public function update(FlowRequest $request, string $tenant, string $record): RedirectResponse
    {
        $this->flows->update($this->flows->findForAssistant($record), $request->fields());

        return $this->backToIndex(trans('console.flows.updated'));
    }

    public function updateActivity(UpdateFlowActivityRequest $request, string $tenant, string $record): RedirectResponse
    {
        $this->flows->setActive($this->flows->findForAssistant($record), $request->active());

        return $this->backToList(trans($request->active() ? 'console.flows.activated' : 'console.flows.deactivated'));
    }

    public function destroy(string $tenant, string $record): RedirectResponse
    {
        $flow = $this->flows->findForAssistant($record);

        Gate::authorize('delete', $flow);

        try {
            $this->flows->delete($flow);
        } catch (FlowHasLiveSessionsException) {
            return $this->backToList(trans('console.flows.delete_blocked'), 'error');
        }

        return $this->backToList(trans('console.flows.deleted'));
    }

    public function destroyMany(DestroyFlowsRequest $request): RedirectResponse
    {
        $result = $this->flows->deleteMany($request->ids());

        if (0 === $result['deleted'] && $result['blocked'] > 0) {
            return $this->backToList(trans('console.flows.delete_blocked'), 'error');
        }

        if ($result['blocked'] > 0) {
            return $this->backToList(trans('console.flows.delete_many_blocked', $result));
        }

        return $this->backToList(trans('console.flows.deleted_many', ['count' => $result['deleted']]));
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
     * After a change made from the list: the list the user was on, with its search, sort, filters and page (the
     * referer), or the default list when there is none.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToList(string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->back(fallback: $this->url('index'));
    }

    /**
     * The flow's page in the builder, by its route name.
     */
    private function builderUrl(FlowDraft $flow): string
    {
        return route('builder.flows.show', ['flow' => $flow->flow_id], false);
    }

    /**
     * A relative URL of one of this screen's routes, inside the current assistant's console.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = match ($action) {
            'index', 'create', 'edit' => 'filament.assistant.resources.flows.' . $action,
            'activity'                => 'console.flows.activity',
            default                   => 'console.flows.' . $action,
        };

        return route($name, ['tenant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }

    private function groupUrl(string $action): string
    {
        return route('console.flow-groups.' . $action, ['tenant' => (string) $this->assistant->get()->getKey()], false);
    }
}
