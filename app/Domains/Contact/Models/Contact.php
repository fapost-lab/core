<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use App\Domains\Channels\Casts\ChannelTypeCast;
use Database\Factories\ContactFactory;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Contact entity per tenant/platform identity.
 *
 * Stores platform-specific identity and arbitrary metadata. Computed attributes can be extended via
 * {@see BaseModel} (e.g. via HR solution).
 *
 * @property string                  $platform
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ChannelContact> $channelContacts
 * @property-read int|null           $channel_contacts_count
 * @property-read bool|null          $channel_contacts_exists
 * @method static \Database\Factories\ContactFactory factory($count = null, $state = [])
 * @method static Builder<static>|Contact newModelQuery()
 * @method static Builder<static>|Contact newQuery()
 * @method static Builder<static>|Contact query()
 * @property string                  $id
 * @property string                  $tenant_id
 * @property string                  $external_id
 * @property array<array-key, mixed> $meta
 * @property array<array-key, mixed> $attributes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string                  $language
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
        'is_authenticated',
        'meta',
        'attributes',
    ];

    /**
     * Channel linkages for this contact.
     *
     * @return HasMany<ChannelContact, $this>
     */
    public function channelContacts(): HasMany
    {
        return $this->hasMany(ChannelContact::class);
    }

    /**
     * Dynamic tags applied to this contact (by flow nodes or staff).
     *
     * @return HasMany<ContactTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(ContactTag::class);
    }

    /**
     * Named groups this contact belongs to (see {@see ContactGroup}).
     *
     * @return BelongsToMany<ContactGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(ContactGroup::class, 'contact_group_members');
    }

    /**
     * The name the platform gave, as the console shows it: first and last name, else `@username`, else null (the
     * screen falls back to the external id).
     */
    public function displayName(): ?string
    {
        $full = mb_trim(($this->metaText('first_name') ?? '') . ' ' . ($this->metaText('last_name') ?? ''));

        if ('' !== $full) {
            return $full;
        }

        $username = $this->metaText('username');

        return null === $username ? null : '@' . $username;
    }

    /**
     * A text the platform put in the contact's meta, trimmed; null when it is missing, empty or not a scalar text.
     */
    public function metaText(string $key): ?string
    {
        $meta  = $this->getAttribute('meta');
        $value = is_array($meta) ? ($meta[$key] ?? null) : null;

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = mb_trim((string) $value);

        return '' === $text ? null : $text;
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
            'platform'         => ChannelTypeCast::class,
            'language'         => 'string',
            'is_authenticated' => 'boolean',
            'meta'             => 'array',
            'attributes'       => 'array',
        ];
    }
}
