<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Models;

use App\Domains\Assistant\Models\Builders\AssistantBuilder;
use App\Domains\Staff\Models\User;
use Database\Factories\AssistantFactory;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;
use Filament\Models\Contracts\HasName;
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
 * @method static AssistantBuilder<static>|Assistant active()
 * @method static \Database\Factories\AssistantFactory factory($count = null, $state = [])
 * @method static AssistantBuilder<static>|Assistant newModelQuery()
 * @method static AssistantBuilder<static>|Assistant newQuery()
 * @method static AssistantBuilder<static>|Assistant query()
 * @mixin \Eloquent
 */
final class Assistant extends BaseModel implements HasName
{
    /** @use HasFactory<AssistantFactory> */
    use HasFactory;
    use HasUlidPrimaryKey;

    /** @var class-string<AssistantBuilder> */
    protected string $customBuilder = AssistantBuilder::class;

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
