<?php

declare(strict_types=1);

namespace App\Domains\Staff\Models;

use App\Domains\Staff\Enums\RoleEnum;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Tenant-scoped role; table lives in the active tenant schema.
 *
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withoutPermission($permissions)
 * @property string $id
 * @property string $name
 * @property string $guard_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string|null $display_name
 * @property bool $is_system
 * @property int $priority
 * @property-read bool|null $permissions_exists
 * @property-read bool|null $users_exists
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereDisplayName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereIsSystem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role wherePriority($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
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
