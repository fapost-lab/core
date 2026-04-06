<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Models;

use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use App\Domains\Shared\Models\BaseModel;
use App\Domains\Staff\Models\User;
use Database\Factories\AssistantFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Assistant business aggregate.
 *
 * Owns assistant settings and lifecycle flags. Channels are transport endpoints under this assistant.
 * Extensible via {@see BaseModel} computed attributes (Solutions/Features/Plugins).
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Channel> $channels
 * @property-read int|null $channels_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 * @property-read int|null $users_count
 * @method static Builder<static>|Assistant active()
 * @method static \Database\Factories\AssistantFactory factory($count = null, $state = [])
 * @method static Builder<static>|Assistant newModelQuery()
 * @method static Builder<static>|Assistant newQuery()
 * @method static Builder<static>|Assistant query()
 * @mixin \Eloquent
 */
final class Assistant extends BaseModel implements HasName
{
    /** @use HasFactory<AssistantFactory> */
    use HasFactory;
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
        'default_flow_id',
        'fallback_message',
        'settings',
    ];

    /**
     * @return HasMany<Channel, $this>
     */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_assistants');
    }

    /**
     * Filament display name for the assistant.
     */
    public function getFilamentName(): string
    {
        return $this->name;
    }

    /**
     * @param  Builder<static>  $query
     *
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): AssistantFactory
    {
        return AssistantFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(function (Assistant $assistant): void {
            $assistant->channels()->each(function (Channel $channel): void {
                $channel->delete();
            });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings'  => 'array',
            'is_active' => 'boolean',
        ];
    }
}
