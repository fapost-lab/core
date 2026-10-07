<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Models;

use App\Domains\Assistant\Models\Builders\AssistantBuilder;
use App\Domains\Channels\Models\Channel;
use App\Domains\Staff\Models\User;
use Database\Factories\AssistantFactory;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
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
 * @property-read int|null                   $channels_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $users
 * @property-read int|null                   $users_count
 * @method static AssistantBuilder<static>|Assistant active()
 * @method static \Database\Factories\AssistantFactory factory($count = null, $state = [])
 * @method static AssistantBuilder<static>|Assistant newModelQuery()
 * @method static AssistantBuilder<static>|Assistant newQuery()
 * @method static AssistantBuilder<static>|Assistant query()
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string                          $name
 * @property bool                            $is_active
 * @property string|null                     $default_flow_id
 * @property array<string, string>|null      $fallback_message
 * @property array<string, string>|null      $busy_message
 * @property list<array<string, mixed>>|null $commands
 * @property array<array-key, mixed>         $settings
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string                          $default_language
 * @property list<string>|null               $available_countries
 * @property-read bool|null                  $channels_exists
 * @property-read bool|null                  $users_exists
 * @method static AssistantBuilder<static>|Assistant whereCreatedAt($value)
 * @method static AssistantBuilder<static>|Assistant whereDefaultFlowId($value)
 * @method static AssistantBuilder<static>|Assistant whereDefaultLanguage($value)
 * @method static AssistantBuilder<static>|Assistant whereFallbackMessage($value)
 * @method static AssistantBuilder<static>|Assistant whereId($value)
 * @method static AssistantBuilder<static>|Assistant whereIsActive($value)
 * @method static AssistantBuilder<static>|Assistant whereName($value)
 * @method static AssistantBuilder<static>|Assistant whereSettings($value)
 * @method static AssistantBuilder<static>|Assistant whereTenantId($value)
 * @method static AssistantBuilder<static>|Assistant whereUpdatedAt($value)
 * @mixin \Eloquent
 */
final class Assistant extends BaseModel implements HasName
{
    /** @use HasFactory<AssistantFactory> */
    use HasFactory;
    use HasUlidPrimaryKey;
    /**
     * Limit key under which a tenant's assistant count is capped.
     */
    public const string LIMIT_KEY = 'assistants';

    /**
     * Name of the global scope Filament's `assistant` panel adds to models owned by an assistant
     * (its panel id plus `_tenancy`). Counting a tenant-wide limit strips it.
     */
    public const string PANEL_TENANCY_SCOPE = 'assistant_tenancy';

    /** @var class-string<AssistantBuilder> */
    protected string $customBuilder = AssistantBuilder::class;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
        'default_language',
        'available_countries',
        'default_flow_id',
        'fallback_message',
        'settings',
        'commands',
        'busy_message',
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
        self::deleting(static function (Assistant $assistant): void {
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
            'settings'         => 'array',
            'is_active'        => 'boolean',
            'default_language' => 'string',
            // List of ISO 3166-1 alpha-2 codes the assistant serves — drives
            // phone-input format options & validation candidates.
            'available_countries' => 'array',
            'commands'            => 'array',
            // Both message fields are jsonb locale maps `{lang: text}`. The
            // {@see \App\Domains\Flow\Contracts\ContentTranslatorInterface}
            // resolves them via {@see ContentTranslatorInterface::resolveField}.
            'fallback_message' => 'array',
            'busy_message'     => 'array',
        ];
    }
}
