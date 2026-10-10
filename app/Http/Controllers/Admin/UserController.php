<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Exceptions\StaffChangeRefusedException;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\CreatePendingUserService;
use App\Domains\Staff\Services\ResendActivationService;
use App\Domains\Staff\Services\StaffUserService;
use App\Domains\Staff\Services\UserService;
use App\Domains\Staff\Support\StaffLimitStatus;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Admin\DestroyStaffUsersRequest;
use App\Http\Requests\Admin\StoreStaffUserRequest;
use App\Http\Requests\Admin\UpdateStaffUserActivityRequest;
use App\Http\Requests\Admin\UpdateStaffUserRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The tenant's staff users in the admin panel, inside the console shell's admin mode, with the rules of the Filament
 * resource it replaces: an invitation instead of a password, activation and deactivation, the activation email sent
 * again, roles given within the actor's priority.
 *
 * Every action authorizes through `UserPolicy`; what must stop an administrator as well (their own account, the last
 * administrator, the platform support user, roles at or above their priority, the staff limit) is refused by the
 * services, and the refusal reaches the user as an error toast.
 */
final class UserController extends Controller
{
    public function __construct(
        private readonly StaffUserService $users,
        private readonly UserService $lifecycle,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $limit = $this->users->limit();
        $table = new DataTable(
            sortable: ['name', 'email', 'status', 'is_active'],
            searchable: ['name', 'email', 'phone'],
            defaultSort: 'name',
        );

        return Inertia::render('Console/Users/Index', [
            'table' => $table->respond(
                $request,
                $this->users->query(),
                fn (User $user): array => $this->row($actor, $user, $limit),
            ),
            'limit' => [
                'reached' => $limit->reached,
                'hint'    => $this->limitHint($limit),
            ],
            'can' => [
                // The policy lets an administrator through whatever the limit says, so the limit is asked on its own.
                'create' => Gate::allows('create', User::class) && ! $limit->reached,
                'delete' => Gate::allows('deleteAny', User::class),
            ],
            'urls' => [
                'index'       => $this->url('index'),
                'create'      => $this->url('create'),
                'destroyMany' => $this->url('destroy-many'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', User::class);

        // Closed at the limit on opening; a form opened below it and saved after the last place went still reaches
        // the service, which refuses with a message.
        abort_if($this->users->limit()->reached, HttpResponse::HTTP_FORBIDDEN);

        /** @var User $actor */
        $actor = $request->user();

        return Inertia::render('Console/Users/Create', [
            'roles' => $this->roleOptions($actor),
            'urls'  => ['index' => $this->url('index'), 'submit' => $this->url('store')],
        ]);
    }

    public function store(StoreStaffUserRequest $request, CreatePendingUserService $invitations): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $invitations->create($actor, $request->fields());
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('staff.users.limit.reached_title') . '. ' . $exception->getMessage());

            return redirect()->back(fallback: $this->url('index'));
        }

        return $this->backToIndex(trans('console.users.invited'));
    }

    public function edit(Request $request, string $record): Response
    {
        $user = $this->users->find($record);

        Gate::authorize('update', $user);
        Gate::authorize('updateProfile', $user);

        /** @var User $actor */
        $actor = $request->user();
        // Nobody changes their own roles; an administrator would pass the Gate, so oneself is ruled out here.
        $canEditRoles = ! $actor->is($user) && Gate::allows('updateRoles', [$user, []]);
        // Without the right to change roles every role is shown read-only, as "kept".
        $assignableId = $canEditRoles
            ? $this->users->assignableRoles($actor)->map(static fn (Role $role): string => (string) $role->getKey())->all()
            : [];

        return Inertia::render('Console/Users/Edit', [
            'user' => [
                'id'    => (string) $user->getKey(),
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                // Only the roles this form can change; the others are listed apart and kept.
                'roles' => $user->roles
                    ->filter(static fn (Role $role): bool => in_array((string) $role->getKey(), $assignableId, true))
                    ->map(static fn (Role $role): string => (string) $role->getKey())
                    ->values()
                    ->all(),
                'keptRoles' => $user->roles
                    ->reject(static fn (Role $role): bool => in_array((string) $role->getKey(), $assignableId, true))
                    ->map(static fn (Role $role): string => $role->title)
                    ->values()
                    ->all(),
            ],
            'roles' => $canEditRoles ? $this->roleOptions($actor) : [],
            'can'   => [
                'editRoles' => $canEditRoles,
                'delete'    => Gate::allows('delete', $user) && ! $actor->is($user),
            ],
            'urls' => [
                'index'   => $this->url('index'),
                'submit'  => $this->url('update', ['record' => $user->getKey()]),
                'destroy' => $this->url('destroy', ['record' => $user->getKey()]),
            ],
        ]);
    }

    public function update(UpdateStaffUserRequest $request, string $record): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->users->update($actor, $this->users->find($record), $request->profile(), $request->roleIds());
        } catch (StaffChangeRefusedException $exception) {
            return $this->refused($exception);
        }

        return $this->backToIndex(trans('console.users.updated'));
    }

    /**
     * Activates or deactivates; the state is sent, so a repeated request changes nothing.
     */
    public function updateActivity(UpdateStaffUserActivityRequest $request, string $record): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $user  = $this->users->find($record);

        try {
            if ($request->active()) {
                $this->lifecycle->activate($actor, $user);
            } else {
                $this->lifecycle->deactivate($actor, $user);
            }
        } catch (RecordLimitReachedException $exception) {
            return $this->backToList(trans('staff.users.limit.reached_title') . '. ' . $exception->getMessage(), 'error');
        } catch (ValidationException $exception) {
            return $this->backToList($this->firstMessage($exception), 'error');
        }

        return $this->backToList(trans($request->active() ? 'console.users.activated' : 'console.users.deactivated'));
    }

    public function resendActivation(string $record, ResendActivationService $resend): RedirectResponse
    {
        $user = $this->users->find($record);

        Gate::authorize('resendActivation', $user);

        try {
            $resend->resend($user);
        } catch (ValidationException $exception) {
            return $this->backToList($this->firstMessage($exception), 'error');
        }

        return $this->backToList(trans('console.users.activation_sent'));
    }

    /**
     * From the edit page: the record is gone afterwards, so the list opens, not the page the request came from.
     */
    public function destroy(Request $request, string $record): RedirectResponse
    {
        $user = $this->users->find($record);

        Gate::authorize('delete', $user);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $this->users->delete($actor, $user);
        } catch (StaffChangeRefusedException $exception) {
            return $this->refused($exception);
        }

        return $this->backToIndex(trans('console.users.deleted'));
    }

    public function destroyMany(DestroyStaffUsersRequest $request): RedirectResponse
    {
        /** @var User $actor */
        $actor  = $request->user();
        $result = $this->users->deleteMany(
            $actor,
            $request->ids(),
            static fn (User $user): bool => Gate::forUser($actor)->allows('delete', $user),
        );

        if ($result['blocked'] > 0) {
            return $this->backToList(trans('console.users.deleted_partly', $result), 0 === $result['deleted'] ? 'error' : 'success');
        }

        return $this->backToList(trans('console.users.deleted_many', ['count' => $result['deleted']]));
    }

    /**
     * A list row with what the actor may do to that user, as the Filament row actions showed them.
     *
     * @return array<string, mixed>
     */
    private function row(User $actor, User $user, StaffLimitStatus $limit): array
    {
        $isSelf = $actor->is($user);

        return [
            'id'          => (string) $user->getKey(),
            'name'        => $user->name,
            'email'       => $user->email,
            'phone'       => $user->phone,
            'status'      => $user->status->value,
            'statusLabel' => trans('staff.users.status.' . $user->status->value),
            'isActive'    => $user->is_active,
            'isSupport'   => $user->isPlatformSupport(),
            'isSelf'      => $isSelf,
            'roles'       => $user->roles->map(static fn (Role $role): string => $role->title)->values()->all(),
            'can'         => [
                'edit'       => Gate::allows('update', $user) && Gate::allows('updateProfile', $user),
                'deactivate' => $user->is_active && ! $isSelf && Gate::allows('deactivate', $user),
                // A deactivated account takes a staff place again when reactivated.
                'activate' => ! $user->is_active && ! $limit->reached && Gate::allows('activate', $user),
                'resend'   => UserStatus::Pending === $user->status && Gate::allows('resendActivation', $user),
            ],
            'editUrl'     => $this->url('edit', ['record' => $user->getKey()]),
            'activityUrl' => $this->url('activity', ['record' => $user->getKey()]),
            'resendUrl'   => $this->url('resend-activation', ['record' => $user->getKey()]),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(User $actor): array
    {
        return $this->users->assignableRoles($actor)
            ->map(static fn (Role $role): array => ['value' => (string) $role->getKey(), 'label' => $role->title])
            ->values()
            ->all();
    }

    private function limitHint(StaffLimitStatus $limit): ?string
    {
        return $limit->reached && null !== $limit->limit
            ? trans('staff.users.limit.hint', ['current' => $limit->current, 'limit' => $limit->limit])
            : null;
    }

    /**
     * A guard said no: back to the page the request came from, with the reason as an error toast.
     */
    private function refused(StaffChangeRefusedException $exception): RedirectResponse
    {
        Inertia::flash('error', trans('console.users.refused.' . $exception->reason));

        return redirect()->back(fallback: $this->url('index'));
    }

    private function firstMessage(ValidationException $exception): string
    {
        $messages = $exception->errors();
        $first    = reset($messages);

        return is_array($first) && isset($first[0]) ? (string) $first[0] : $exception->getMessage();
    }

    private function backToIndex(string $message): RedirectResponse
    {
        Inertia::flash('success', $message);

        return redirect()->to($this->url('index'));
    }

    /**
     * After an action from the list: the list the user was on (the referer), or the default list.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToList(string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->back(fallback: $this->url('index'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = match ($action) {
            'index', 'create', 'edit' => 'filament.admin.resources.users.' . $action,
            default                   => 'console.admin.users.' . $action,
        };

        return route($name, $parameters, false);
    }
}
