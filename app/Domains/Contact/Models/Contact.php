<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use App\Domains\Contact\Enums\PlatformEnum;
use Database\Factories\ContactFactory;
use FAPost\Support\Concerns\HasUlidPrimaryKey;
use FAPost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contact entity per tenant/platform identity.
 *
 * Stores platform-specific identity and arbitrary metadata. Computed attributes can be extended via
 * {@see BaseModel} (e.g. via HR solution).
 *
 * @property PlatformEnum $platform
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChannelContact> $channelContacts
 * @property-read int|null $channel_contacts_count
 * @property-read bool|null $channel_contacts_exists
 * @method static \Database\Factories\ContactFactory factory($count = null, $state = [])
 * @method static Builder<static>|Contact newModelQuery()
 * @method static Builder<static>|Contact newQuery()
 * @method static Builder<static>|Contact query()
 * @property string $id
 * @property string $tenant_id
 * @property string $external_id
 * @property array<array-key, mixed> $meta
 * @property array<array-key, mixed> $attributes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $language
 * @method static Builder<static>|Contact whereAttributes($value)
 * @method static Builder<static>|Contact whereCreatedAt($value)
 * @method static Builder<static>|Contact whereExternalId($value)
 * @method static Builder<static>|Contact whereId($value)
 * @method static Builder<static>|Contact whereLanguage($value)
 * @method static Builder<static>|Contact whereMeta($value)
 * @method static Builder<static>|Contact wherePlatform($value)
 * @method static Builder<static>|Contact whereTenantId($value)
 * @method static Builder<static>|Contact whereUpdatedAt($value)
 * @mixin \Eloquent
 */
final class Contact extends BaseModel
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
        'language',
        'meta',
        'attributes',
    ];

    /**
     * Channel linkages for this contact.
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
            'language'   => 'string',
            'meta'       => 'array',
            'attributes' => 'array',
        ];
    }
}
