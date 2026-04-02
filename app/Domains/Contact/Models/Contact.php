<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Shared\Concerns\HasUlidPrimaryKey;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string                          $id
 * @property string                          $tenant_id
 * @property PlatformEnum                    $platform
 * @property string                          $external_id
 * @property array<string, mixed>            $meta
 * @property array<string, mixed>            $attributes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @method static Builder<static>|Contact query()
 * @method static ContactFactory factory($count = null, $state = [])
 */
final class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'platform',
        'external_id',
        'meta',
        'attributes',
    ];

    /**
     * @return HasMany<ChannelContact, $this>
     */
    public function channelContacts(): HasMany
    {
        return $this->hasMany(ChannelContact::class);
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform'   => PlatformEnum::class,
            'meta'       => 'array',
            'attributes' => 'array',
        ];
    }
}
