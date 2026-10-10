<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Staff\Models\Role;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\StaffRoleService;
use App\Domains\Staff\Services\StaffUserService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The admin shell's search palette, in place of Filament's global search over the same four resources: assistants,
 * staff users, roles and media files.
 *
 * Each group is searched only for a user who may list that kind of record, through the same bounded query its list
 * uses (assistants: all for an administrator, the assigned ones otherwise; media: the current tenant's), and returns at
 * most {@see self::LIMIT} hits. A hit carries a title, a subtitle and the URL of the screen that opens it; never a
 * storage path or anything else the list would not show.
 */
final readonly class AdminSearch
{
    /**
     * The shortest text searched; anything shorter returns no groups.
     */
    public const int MIN_LENGTH = 2;

    /**
     * Hits per group.
     */
    public const int LIMIT = 5;

    public function __construct(
        private TenantContextInterface $tenants,
        private StaffAssistantService $assistants,
        private StaffUserService $users,
        private StaffRoleService $roles,
        private RouteOwnership $ownership,
    ) {
    }

    /**
     * Whether the user may search anything at all: the shell shows the palette only then.
     */
    public function isAvailableTo(User $user): bool
    {
        $gate = Gate::forUser($user);

        foreach ([Assistant::class, User::class, Role::class, MediaFile::class] as $model) {
            if ($gate->allows('viewAny', $model)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The groups with at least one hit, in a fixed order.
     *
     * @return list<array{key: string, items: list<array{id: string, title: string, subtitle: string|null, url: string, external: bool}>}>
     */
    public function search(User $user, string $text): array
    {
        $text = mb_trim($text);

        if (mb_strlen($text) < self::MIN_LENGTH) {
            return [];
        }

        $gate   = Gate::forUser($user);
        $groups = [
            'assistants' => fn (): array => $this->assistantHits($user, $text),
            'users'      => fn (): array => $this->userHits($gate, $text),
            'roles'      => fn (): array => $this->roleHits($text),
            'media'      => fn (): array => $this->mediaHits($text),
        ];
        $models = ['assistants' => Assistant::class, 'users' => User::class, 'roles' => Role::class, 'media' => MediaFile::class];
        $result = [];

        foreach ($groups as $key => $hits) {
            if (! $gate->allows('viewAny', $models[$key])) {
                continue;
            }

            $items = $hits();

            if ([] !== $items) {
                $result[] = ['key' => $key, 'items' => $items];
            }
        }

        return $result;
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, url: string, external: bool}>
     */
    private function assistantHits(User $user, string $text): array
    {
        $query = $this->matching($this->assistants->query($user), ['name'], $text)->orderBy('name');

        return $query->limit(self::LIMIT)->get()
            ->map(fn (Assistant $assistant): array => $this->hit(
                $assistant,
                (string) $assistant->name,
                null,
                'filament.admin.resources.assistants.view',
                ['record' => $assistant->getKey()],
            ))
            ->values()
            ->all();
    }

    /**
     * A user opens on their edit page when the actor may change them, as Filament's result did, otherwise on the list
     * narrowed to them.
     *
     * @return list<array{id: string, title: string, subtitle: string|null, url: string, external: bool}>
     */
    private function userHits(GateContract $gate, string $text): array
    {
        $query = $this->matching($this->users->query(), ['name', 'email'], $text)->orderBy('name');

        return $query->limit(self::LIMIT)->get()
            ->map(function (User $user) use ($gate): array {
                $editable = $gate->allows('update', $user) && $gate->allows('updateProfile', $user);

                return $editable
                    ? $this->hit($user, (string) $user->name, (string) $user->email, 'filament.admin.resources.users.edit', ['record' => $user->getKey()])
                    : $this->hit($user, (string) $user->name, (string) $user->email, 'filament.admin.resources.users.index', ['search' => (string) $user->email]);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, url: string, external: bool}>
     */
    private function roleHits(string $text): array
    {
        $query = $this->matching($this->roles->query(), ['name', 'display_name'], $text)->orderBy('name');

        return $query->limit(self::LIMIT)->get()
            ->map(fn (Role $role): array => $this->hit(
                $role,
                $role->title,
                $role->title === $role->name ? null : $role->name,
                'filament.admin.resources.roles.edit',
                ['record' => $role->getKey()],
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, title: string, subtitle: string|null, url: string, external: bool}>
     */
    private function mediaHits(string $text): array
    {
        $query = $this->matching(
            MediaFile::query()->where('tenant_id', $this->tenants->get()->getId()),
            ['name'],
            $text,
        )->orderBy('name');

        return $query->limit(self::LIMIT)->get()
            ->map(fn (MediaFile $file): array => $this->hit(
                $file,
                $file->name,
                trans('media.kinds.' . $file->kind->value),
                'filament.admin.resources.media.view',
                ['record' => $file->getKey()],
            ))
            ->values()
            ->all();
    }

    /**
     * Narrows the query to rows whose columns contain the text, case-insensitively, the way the lists search
     * (`LOWER() LIKE` with `!` as the escape, so one statement runs on SQLite and PostgreSQL).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     *
     * @return Builder<TModel>
     */
    private function matching(Builder $query, array $columns, string $text): Builder
    {
        $grammar = $query->getQuery()->getGrammar();
        $table   = $query->getModel()->getTable();
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text)) . '%';

        return $query->where(static function (Builder $group) use ($grammar, $table, $columns, $pattern): void {
            foreach ($columns as $column) {
                $group->orWhereRaw('LOWER(' . $grammar->wrap($table . '.' . $column) . ") LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @return array{id: string, title: string, subtitle: string|null, url: string, external: bool}
     */
    private function hit(Model $record, string $title, ?string $subtitle, string $route, array $parameters): array
    {
        return [
            'id'       => (string) $record->getKey(),
            'title'    => $title,
            'subtitle' => $subtitle,
            'url'      => route($route, $parameters, false),
            'external' => ! $this->ownership->isMigrated($route),
        ];
    }
}
