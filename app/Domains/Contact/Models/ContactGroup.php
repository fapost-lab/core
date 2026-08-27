<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * Named audience list for targeting broadcasts. One of the three segmentation
 * concepts (Groups / Tags / Segments) — unlike a {@see ContactTag} (free-form,
 * per-write), membership is an explicit pivot ({@see contacts()}) managed by
 * staff, and can itself be referenced from a
 * {@see \App\Domains\Contact\Services\ContactSegmentResolver} condition.
 * Groups are tenant-level, not assistant-level.
 *
 * @property string      $id
 * @property string      $tenant_id
 * @property string      $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ContactGroup extends BaseModel
{
    use HasUlidPrimaryKey;

    protected $table = 'contact_groups';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'description',
    ];

    /**
     * Contacts assigned to this group.
     *
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_group_members');
    }
}
