<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Exceptions\FlowGroupNotEmptyException;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Services\FlowGroupService;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\DestroyFlowGroupsRequest;
use App\Http\Requests\Console\FlowGroupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Flow groups on the Inertia console. A group belongs to the assistant in the URL, which {@see FlowGroupService}
 * bounds every read and write by.
 *
 * Deleting a group that still has flows is refused by the service; this controller turns the refusal into an error toast
 * and keeps the user where they were.
 */
final class FlowGroupController extends Controller
{
    public function __construct(
        private readonly FlowGroupService $groups,
        private readonly CurrentAssistantInterface $assistant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', FlowGroup::class);

        $table = new DataTable(sortable: ['name', 'drafts_count'], searchable: ['name'], defaultSort: 'name');

        return Inertia::render('Console/FlowGroups/Index', [
            'table' => $table->respond(
                $request,
                $this->groups->query()->withCount('drafts'),
                fn (FlowGroup $group): array => [
                    'id'         => (string) $group->getKey(),
                    'name'       => $group->name,
                    'flowsCount' => (int) $group->getAttribute('drafts_count'),
                    'editUrl'    => $this->url('edit', ['record' => $group->getKey()]),
                    'deleteUrl'  => $this->url('destroy', ['record' => $group->getKey()]),
                ],
            ),
            'can' => [
                'create' => Gate::allows('create', FlowGroup::class),
                'update' => Gate::allows('update', new FlowGroup()),
                'delete' => Gate::allows('deleteAny', FlowGroup::class),
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
        Gate::authorize('create', FlowGroup::class);

        return Inertia::render('Console/FlowGroups/Create', [
            'urls' => ['index' => $this->url('index'), 'submit' => $this->url('store')],
        ]);
    }

    public function store(FlowGroupRequest $request): RedirectResponse
    {
        $this->groups->create(['name' => (string) $request->validated('name')]);

        return $this->backToIndex(trans('console.flow_groups.created'));
    }

    /**
     * Creates a group from a flow's form, without leaving it: the user is sent back to the form's page, and the new
     * group's id rides along as flash data so the form can select it.
     */
    public function storeInline(FlowGroupRequest $request): RedirectResponse
    {
        $group = $this->groups->create(['name' => (string) $request->validated('name')]);

        Inertia::flash(['success' => trans('console.flow_groups.created'), 'flowGroupId' => (string) $group->getKey()]);

        return redirect()->back(fallback: $this->url('index'));
    }

    /*
     * Route parameters reach an action by position, not by name, so `$tenant` (the assistant in the URL, already
     * resolved by the console stack) has to be declared ahead of `$record`.
     */
    public function edit(string $tenant, string $record): Response
    {
        $group = $this->groups->findForAssistant($record);

        Gate::authorize('update', $group);

        return Inertia::render('Console/FlowGroups/Edit', [
            'group' => ['id' => (string) $group->getKey(), 'name' => $group->name],
            'urls'  => ['index' => $this->url('index'), 'submit' => $this->url('update', ['record' => $group->getKey()])],
        ]);
    }

    public function update(FlowGroupRequest $request, string $tenant, string $record): RedirectResponse
    {
        $this->groups->update($this->groups->findForAssistant($record), ['name' => (string) $request->validated('name')]);

        return $this->backToIndex(trans('console.flow_groups.updated'));
    }

    public function destroy(string $tenant, string $record): RedirectResponse
    {
        $group = $this->groups->findForAssistant($record);

        Gate::authorize('delete', $group);

        try {
            $this->groups->delete($group);
        } catch (FlowGroupNotEmptyException) {
            return $this->backToList(trans('console.flow_groups.delete_blocked'), 'error');
        }

        return $this->backToList(trans('console.flow_groups.deleted'));
    }

    public function destroyMany(DestroyFlowGroupsRequest $request): RedirectResponse
    {
        $result = $this->groups->deleteMany($request->ids());

        if (0 === $result['deleted'] && $result['blocked'] > 0) {
            return $this->backToList(trans('console.flow_groups.delete_blocked'), 'error');
        }

        if ($result['blocked'] > 0) {
            return $this->backToList(trans('console.flow_groups.delete_many_blocked', $result));
        }

        return $this->backToList(trans('console.flow_groups.deleted_many', ['count' => $result['deleted']]));
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
     * After a delete from the list: the list the user was on, with its search, sort and page (the referer), or the
     * default list when there is none.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToList(string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

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
            'index', 'create', 'edit' => 'filament.assistant.resources.flow-groups.' . $action,
            default                   => 'console.flow-groups.' . $action,
        };

        return route($name, ['tenant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
