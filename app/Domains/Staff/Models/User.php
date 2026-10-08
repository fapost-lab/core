<?php

declare(strict_types=1);

namespace App\Domains\Staff\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Database\Factories\UserFactory;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User countedForLimit()
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
 * @property bool
 *                   $is_platform_support
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
     * Limit key under which a tenant's staff count is capped.
     */
    public const string LIMIT_KEY = 'staff';

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
     * How many staff places the tenant uses.
     */
    public static function countForLimit(): int
    {
        return self::query()->countedForLimit()->count();
    }

    /**
     * Active status AND not deactivated by admin.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return UserStatus::Active === $this->status && $this->is_active;
    }

    /**
     * Users that take a staff place: every account that is not deactivated (`is_active`), pending
     * (invited) and suspended ones included, because an invitation is a reserved place. A deactivated account frees its place.
     *
     * This is the one seam for accounts that must not count: the platform's support user is
     * excluded here, so every count, hint and check follows.
     *
     * @param  Builder<User>  $query
     *
     * @return Builder<User>
     */
    public function scopeCountedForLimit(Builder $query): Builder
    {
        return $query->where('is_active', true)->withoutPlatformSupport();
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(RoleEnum::Admin->value);
    }

    /**
     * The tenant's one service account an operator enters through (see the support access ADR).
     * It has no password and the tenant's administrators cannot change or remove it.
     */
    public function isPlatformSupport(): bool
    {
        return true === $this->is_platform_support;
    }

    /**
     * People of the tenant: everything except its platform support user.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWithoutPlatformSupport(Builder $query): Builder
    {
        return $query->where('is_platform_support', false);
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

    /**
     * Whether the user may work with the given assistant: admins reach every assistant,
     * everyone else only the ones assigned through {@see assistants()}.
     */
    public function hasAssistantAccess(Assistant $assistant): bool
    {
        return $this->isAdmin() || $this->assistants()->whereKey($assistant->getKey())->exists();
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

    protected static function booted(): void
    {
        // Row deletes only: dropping a tenant's schema removes the account with everything else.
        static::deleting(static fn (User $user): bool => ! $user->isPlatformSupport());
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
            'email_verified_at'   => 'datetime',
            'password'            => 'hashed',
            'status'              => UserStatus::class,
            'is_active'           => 'boolean',
            'is_platform_support' => 'boolean',
        ];
    }
}
