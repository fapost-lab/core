<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Models;

use App\Domains\Assistant\Enums\ChannelTypeEnum;
use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string                          $id
 * @property string                          $assistant_id
 * @property string                          $tenant_id
 * @property ChannelTypeEnum                 $type
 * @property string                          $token
 * @property string                          $secret_token
 * @property string                          $webhook_public_hash
 * @property array<string, mixed>            $config
 * @property bool                            $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<static>|Channel active()
 * @method static ChannelFactory factory($count = null, $state = [])
 */
final class Channel extends Model
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
            if ('' === (string) $channel->webhook_public_hash) {
                $channel->webhook_public_hash = Str::random(48);
            }

            if ($channel->assistant_id && '' === (string) $channel->tenant_id) {
                $tenantId = Assistant::query()->whereKey($channel->assistant_id)->value('tenant_id');

                if (null !== $tenantId && '' !== (string) $tenantId) {
                    $channel->tenant_id = (string) $tenantId;
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
