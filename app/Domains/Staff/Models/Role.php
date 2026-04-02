<?php

declare(strict_types=1);

namespace App\Domains\Staff\Models;

use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use App\Domains\Staff\Enums\RoleEnum;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Tenant-scoped role; table lives in the active tenant schema.
 *
 * @property string $id
 * @property int $priority
 * @property bool $is_system
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withoutPermission($permissions)
 * @mixin \Eloquent
 */
final class Role extends SpatieRole
{
    use HasUlidPrimaryKey;

    private const STAFF_GUARD = 'web';

    /**
     * Maximum priority among the given role collection (0 if empty).
     *
     * @param  Collection<int, self>|\Illuminate\Support\Collection<int, self>  $roles
     */
    public static function maxPriority(Collection|\Illuminate\Support\Collection $roles): int
    {
        return (int) $roles->max('priority');
    }

    public function isAdminRole(): bool
    {
        return $this->name === RoleEnum::Admin->value;
    }

    public function isSystemRole(): bool
    {
        return $this->is_system;
    }

    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            if (null === $role->guard_name || '' === $role->guard_name) {
                $role->guard_name = self::STAFF_GUARD;
            }
        });

        static::deleting(function (Role $role): void {
            if ($role->isSystemRole()) {
                throw new AuthorizationException(__('System roles cannot be deleted.'));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'priority'  => 'integer',
        ];
    }
}
