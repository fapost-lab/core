<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Services\ContactGroupService;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\ContactGroupRequest;
use App\Http\Requests\Console\DestroyContactGroupsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contact groups on the Inertia console: the pilot screen whose shape the next list-and-form screens copy.
 *
 * The controller orchestrates: it authorizes, hands the work to {@see ContactGroupService} and the list to
 * {@see DataTable}, and answers with a page or a redirect carrying a `success` flash for the toast. Groups are
 * tenant-level; the assistant in the URL only places the screen inside the console.
 */
final class ContactGroupController extends Controller
{
    public function __construct(
        private readonly ContactGroupService $groups,
        private readonly CurrentAssistantInterface $assistant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ContactGroup::class);

        $table = new DataTable(sortable: ['name', 'created_at'], searchable: ['name'], defaultSort: 'name');

        return Inertia::render('Console/ContactGroups/Index', [
            'table' => $table->respond(
                $request,
                $this->groups->query()->withCount('contacts'),
                fn (ContactGroup $group): array => [
                    'id'            => (string) $group->getKey(),
                    'name'          => $group->name,
                    'description'   => $group->description,
                    'contactsCount' => (int) $group->getAttribute('contacts_count'),
                    'createdAt'     => $group->created_at?->toIso8601String(),
                    'editUrl'       => $this->url('edit', ['record' => $group->getKey()]),
                    'deleteUrl'     => $this->url('destroy', ['record' => $group->getKey()]),
                ],
            ),
            'can' => [
                'create' => Gate::allows('create', ContactGroup::class),
                'update' => Gate::allows('update', new ContactGroup()),
                'delete' => Gate::allows('deleteAny', ContactGroup::class),
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
        Gate::authorize('create', ContactGroup::class);

        return Inertia::render('Console/ContactGroups/Create', [
            'urls' => ['index' => $this->url('index'), 'submit' => $this->url('store')],
        ]);
    }

    public function store(ContactGroupRequest $request): RedirectResponse
    {
        /** @var array{name: string, description?: string|null} $data */
        $data = $request->validated();

        $this->groups->create($data);

        return $this->backToIndex(trans('console.contact_groups.created'));
    }

    /*
     * Route parameters reach an action by position, not by name, so `$tenant` (the assistant in the URL, already
     * resolved by the console stack) has to be declared ahead of `$record`.
     */
    public function edit(string $tenant, string $record): Response
    {
        $group = $this->groups->findForTenant($record);

        Gate::authorize('update', $group);

        return Inertia::render('Console/ContactGroups/Edit', [
            'group' => [
                'id'          => (string) $group->getKey(),
                'name'        => $group->name,
                'description' => $group->description,
            ],
            'urls' => ['index' => $this->url('index'), 'submit' => $this->url('update', ['record' => $group->getKey()])],
        ]);
    }

    public function update(ContactGroupRequest $request, string $tenant, string $record): RedirectResponse
    {
        /** @var array{name: string, description?: string|null} $data */
        $data = $request->validated();

        $this->groups->update($this->groups->findForTenant($record), $data);

        return $this->backToIndex(trans('console.contact_groups.updated'));
    }

    public function destroy(string $tenant, string $record): RedirectResponse
    {
        $group = $this->groups->findForTenant($record);

        Gate::authorize('delete', $group);

        $this->groups->delete($group);

        return $this->backToList(trans('console.contact_groups.deleted'));
    }

    public function destroyMany(DestroyContactGroupsRequest $request): RedirectResponse
    {
        $count = $this->groups->deleteMany($request->ids());

        return $this->backToList(trans('console.contact_groups.deleted_many', ['count' => $count]));
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
            'index', 'create', 'edit' => 'filament.assistant.resources.contact-groups.' . $action,
            default                   => 'console.contact-groups.' . $action,
        };

        return route($name, ['tenant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
