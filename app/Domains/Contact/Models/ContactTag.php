<?php

declare(strict_types=1);

namespace App\Domains\Contact\Models;

use Database\Factories\ContactTagFactory;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dynamic tag applied to a contact.
 *
 * Tags are one of the three segmentation concepts (Groups / Tags / Segments)
 * and are written by the {@see \App\Domains\Flow\Handlers\SetTagNodeHandler}.
 * The tag string is language-agnostic and never translated.
 *
 * @property string                          $id
 * @property string                          $contact_id
 * @property string                          $tag
 * @property string|null                     $tagged_by  flow_session_id or staff user id
 * @property \Illuminate\Support\Carbon       $tagged_at
 */
final class ContactTag extends BaseModel
{
    /** @use HasFactory<ContactTagFactory> */
    use HasFactory;
    use HasUlidPrimaryKey;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'tag',
        'tagged_by',
        'tagged_at',
    ];

    /**
     * The contact this tag belongs to.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    protected static function newFactory(): ContactTagFactory
    {
        return ContactTagFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tagged_at' => 'datetime',
        ];
    }
}
