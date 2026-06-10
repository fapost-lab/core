<?php

declare(strict_types=1);

namespace App\Domains\Channels\Models;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Observers\ChannelObserver;
use Database\Factories\ChannelFactory;
use FAPost\Foundation\Channel\ChannelInterface;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
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
