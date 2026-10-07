<?php

declare(strict_types=1);

namespace App\Domains\Channels\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Observers\ChannelObserver;
use Database\Factories\ChannelFactory;
use Fapost\Foundation\Channel\ChannelInterface;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Assistant transport endpoint.
 *
 * Stores channel identity (webhook hash) and connection data for a specific assistant.
 * Mutations of `webhook_public_hash` must go through
 * {@see \App\Domains\Channels\Services\ChannelService::rotateWebhookHash()}.
 *
 * @property ChannelTypeEnum     $type
 * @property-read Assistant|null $assistant
 * @method static Builder<static>|Channel active()
 * @method static \Database\Factories\ChannelFactory factory($count = null, $state = [])
 * @method static Builder<static>|Channel newModelQuery()
 * @method static Builder<static>|Channel newQuery()
 * @method static Builder<static>|Channel query()
 * @property string              $id
 * @property string              $assistant_id
 * @property string              $tenant_id
 * @property string              $token
 * @property string              $secret_token
 * @property string|null         $telegram_bot_username
 * @property string              $webhook_public_hash
 * @property array<array-key, mixed> $config
 * @property bool                $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static Builder<static>|Channel whereAssistantId($value)
 * @method static Builder<static>|Channel whereConfig($value)
 * @method static Builder<static>|Channel whereCreatedAt($value)
 * @method static Builder<static>|Channel whereId($value)
 * @method static Builder<static>|Channel whereIsActive($value)
 * @method static Builder<static>|Channel whereSecretToken($value)
 * @method static Builder<static>|Channel whereTelegramBotUsername($value)
 * @method static Builder<static>|Channel whereTenantId($value)
 * @method static Builder<static>|Channel whereToken($value)
 * @method static Builder<static>|Channel whereType($value)
 * @method static Builder<static>|Channel whereUpdatedAt($value)
 * @method static Builder<static>|Channel whereWebhookPublicHash($value)
 * @mixin \Eloquent
 */
#[ObservedBy([ChannelObserver::class])]
final class Channel extends Model implements ChannelInterface
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;
    use HasUlidPrimaryKey;

    /**
     * Limit key under which a tenant's channel count is capped. Inactive channels count too;
     * deleting one frees its place.
     */
    public const string LIMIT_KEY = 'channels';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'assistant_id',
        'tenant_id',
        'type',
        'token',
        'secret_token',
        'config',
        'is_active',
    ];

    /**
     * How many channels the tenant has, across all its assistants.
     *
     * The assistant panel scopes this model to the current assistant through Filament's
     * tenancy global scope ({@see Assistant::PANEL_TENANCY_SCOPE}); the limit counts the whole tenant.
     */
    public static function countForLimit(): int
    {
        return self::query()->withoutGlobalScope(Assistant::PANEL_TENANCY_SCOPE)->count();
    }

    public function getId(): string
    {
        return (string)$this->id;
    }

    public function getType(): string
    {
        return $this->type->value;
    }

    /**
     * Provider transport credentials and per-channel options consumed by adapters.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'token'                 => $this->token,
            'secret_token'          => $this->secret_token,
            'webhook_public_hash'   => $this->webhook_public_hash,
            'telegram_bot_username' => $this->telegram_bot_username,
            'options'               => is_array($this->config) ? $this->config : [],
        ];
    }

    /**
     * Provider-reported account name, without the leading `@`. Null until the
     * provider handshake stored the bot identity
     * ({@see \App\Domains\Channels\Telegram\TelegramWebhookRegistrar}) or for
     * channel types that have no public account name.
     */
    public function publicUsername(): ?string
    {
        $username = match ($this->type) {
            ChannelTypeEnum::Telegram => $this->telegram_bot_username,
            ChannelTypeEnum::WhatsApp => null,
        };

        return '' === (string)$username ? null : (string)$username;
    }

    /**
     * Public handle of the channel as a person would address it — `@bot` for
     * Telegram. Null under the same conditions as {@see publicUsername()}.
     */
    public function publicHandle(): ?string
    {
        $username = $this->publicUsername();

        return null === $username ? null : '@' . $username;
    }

    /**
     * Deep link that opens the channel for an end user, e.g. `https://t.me/bot`.
     * Null under the same conditions as {@see publicHandle()}.
     */
    public function publicUrl(): ?string
    {
        $username = $this->publicUsername();

        return match ($this->type) {
            ChannelTypeEnum::Telegram => null === $username ? null : 'https://t.me/' . $username,
            ChannelTypeEnum::WhatsApp => null,
        };
    }

    /**
     * @return BelongsTo<Assistant, $this>
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(Assistant::class);
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

    protected static function newFactory(): ChannelFactory
    {
        return ChannelFactory::new();
    }

    protected static function booted(): void
    {
        self::creating(static function (Channel $channel): void {
            if ('' === (string)$channel->webhook_public_hash) {
                $channel->webhook_public_hash = Str::random(48);
            }

            if ($channel->assistant_id && '' === (string)$channel->tenant_id) {
                $tenantId = Assistant::query()->whereKey($channel->assistant_id)->value('tenant_id');

                if (null !== $tenantId && '' !== (string)$tenantId) {
                    $channel->tenant_id = (string)$tenantId;
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type'         => ChannelTypeEnum::class,
            'token'        => 'encrypted',
            'secret_token' => 'encrypted',
            'config'       => 'array',
            'is_active'    => 'boolean',
        ];
    }
}
