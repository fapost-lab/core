<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Models;

use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use App\Domains\Staff\Models\User;
use Database\Factories\AssistantFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string                          $name
 * @property bool                            $is_active
 * @property string|null                     $default_flow_id
 * @property string|null                     $fallback_message
 * @property array<string, mixed>            $settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<static>|Assistant active()
 * @method static AssistantFactory factory($count = null, $state = [])
 */
final class Assistant extends Model implements HasName
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
