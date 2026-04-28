<?php

declare(strict_types=1);

namespace App\Domains\Staff\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Database\Factories\UserFactory;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Traits\HasRoles;

/**
 * Tenant-scoped staff user; table lives in the active tenant schema (not landlord/public).
 *
 * @property UserStatus
 *                   $status
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Assistant>
 *                        $assistants
 * @property-read int|null
 *                        $assistants_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int,
 *                \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null
 *                        $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Permission>
 *                        $permissions
 * @property-read int|null
 *                        $permissions_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Role>
 *                        $roles
 * @property-read int|null
 *                        $roles_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, $guard = null, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, $guard = null)
 * @property string
 *                   $id
 * @property string
 *                   $name
 * @property string
 *                   $email
 * @property \Illuminate\Support\Carbon|null
 *                   $email_verified_at
 * @property string|null
 *                   $password
 * @property string|null
 *                   $remember_token
 * @property \Illuminate\Support\Carbon|null
 *                   $created_at
 * @property \Illuminate\Support\Carbon|null
 *                   $updated_at
 * @property string|null
 *                   $phone
 * @property bool
 *                   $is_active
 * @property-read bool|null
 *                        $assistants_exists
 * @property-read bool|null
 *                        $notifications_exists
 * @property-read bool|null
 *                        $permissions_exists
 * @property-read bool|null
 *                        $roles_exists
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
final class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use HasRoles;
    use HasUlidPrimaryKey;
    use Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'status',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Active status AND not deactivated by admin.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return UserStatus::Active === $this->status && $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleEnum::Admin->value);
    }

    /**
     * Task 06.2: assignment to assistants; pivot DDL is owned by task 06b
     * ({@code database/migrations/tenant/2026_03_27_210002_create_user_assistants_table.php}).
     *
     * @return BelongsToMany<Assistant, $this>
     */
    public function assistants(): BelongsToMany
    {
        return $this->belongsToMany(Assistant::class, 'user_assistants');
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Assistant && Gate::forUser($this)->allows('view', $tenant);
    }

    /**
     * @param  Panel  $panel
     *
     * @return Collection<int, Assistant>
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function getTenants(Panel $panel): Collection
    {
        if ('assistant' !== $panel->getId()) {
            return collect();
        }

        $platformTenant = app(TenantContextInterface::class)->get();

        return Assistant::query()
            ->where('tenant_id', $platformTenant->getId())
            ->orderBy('name')
            ->get()
            ->filter(fn (Assistant $assistant): bool => Gate::forUser($this)->allows('view', $assistant))
            ->values();
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'status'            => UserStatus::class,
            'is_active'         => 'boolean',
        ];
    }
}
