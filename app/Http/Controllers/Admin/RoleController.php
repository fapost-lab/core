<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Staff\Exceptions\StaffChangeRefusedException;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\RoleWriterService;
use App\Domains\Staff\Services\StaffRoleService;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Admin\DestroyRolesRequest;
use App\Http\Requests\Admin\RoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's staff roles in the admin panel, inside the console shell's admin mode: a role's name, display name and
 * permissions, chosen from the catalogue in its groups. Only an administrator with `ManageUsers` reaches it
 * (`RolePolicy`). A system role keeps its name and cannot be deleted; writes go through {@see RoleWriterService},
 * which flushes the tenant's permission cache after the commit.
 */
final class RoleController extends Controller
{
    public function __construct(
        private readonly StaffRoleService $roles,
        private readonly RoleWriterService $writer,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Role::class);

        $table = new DataTable(
            sortable: ['name', 'permissions_count'],
            searchable: ['name', 'display_name'],
            defaultSort: 'name',
        );

        return Inertia::render('Console/Roles/Index', [
            'table' => $table->respond(
                $request,
                $this->roles->query(),
                fn (Role $role): array => [
                    'id'               => (string) $role->getKey(),
                    'name'             => $role->name,
                    'displayName'      => $role->display_name,
                    'isSystem'         => $role->is_system,
                    'permissionsCount' => (int) $role->getAttribute('permissions_count'),
                    'editUrl'          => $this->url('edit', ['record' => $role->getKey()]),
                ],
            ),
            'can' => [
                'create' => Gate::allows('create', Role::class),
                'update' => Gate::allows('update', new Role()),
                'delete' => Gate::allows('deleteAny', Role::class),
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
        Gate::authorize('create', Role::class);

        return Inertia::render('Console/Roles/Create', [
            'catalogue' => $this->roles->catalogue(),
            'urls'      => ['index' => $this->url('index'), 'submit' => $this->url('store')],
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->writer->create($actor, $request->fields());

        return $this->backToIndex(trans('console.roles.created'));
    }

    public function edit(string $record): Response
    {
        $role = $this->roles->find($record);

        Gate::authorize('update', $role);

        return Inertia::render('Console/Roles/Edit', [
            'role' => [
                'id'          => (string) $role->getKey(),
                'name'        => $role->name,
                'displayName' => $role->display_name,
                'isSystem'    => $role->is_system,
                'permissions' => $this->roles->permissionsOf($role),
            ],
            'catalogue' => $this->roles->catalogue(),
            'can'       => [
                'delete' => ! $role->is_system && Gate::allows('delete', $role),
            ],
            'urls' => [
                'index'   => $this->url('index'),
                'submit'  => $this->url('update', ['record' => $role->getKey()]),
                'destroy' => $this->url('destroy', ['record' => $role->getKey()]),
            ],
        ]);
    }

    public function update(RoleRequest $request, string $record): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $this->writer->update($actor, $this->roles->find($record), $request->fields());

        return $this->backToIndex(trans('console.roles.updated'));
    }

    /**
     * From the edit page: the record is gone afterwards, so the list opens.
     */
    public function destroy(string $record): RedirectResponse
    {
        $role = $this->roles->find($record);

        Gate::authorize('delete', $role);

        try {
            $this->roles->delete($role);
        } catch (StaffChangeRefusedException) {
            Inertia::flash('error', trans('console.roles.refused.system_role'));

            return redirect()->back(fallback: $this->url('index'));
        }

        return $this->backToIndex(trans('console.roles.deleted'));
    }

    public function destroyMany(DestroyRolesRequest $request): RedirectResponse
    {
        $result = $this->roles->deleteMany($request->ids());

        if ($result['blocked'] > 0) {
            Inertia::flash(0 === $result['deleted'] ? 'error' : 'success', trans('console.roles.deleted_partly', $result));
        } else {
            Inertia::flash('success', trans('console.roles.deleted_many', ['count' => $result['deleted']]));
        }

        return redirect()->back(fallback: $this->url('index'));
    }

    private function backToIndex(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return redirect()->to($this->url('index'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = match ($action) {
            'index', 'create', 'edit' => 'filament.admin.resources.roles.' . $action,
            default                   => 'console.admin.roles.' . $action,
        };

        return route($name, $parameters, false);
    }
}
