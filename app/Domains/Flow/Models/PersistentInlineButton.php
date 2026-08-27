<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;

/**
 * Represents a keep-forever inline button registration.
 *
 * Created when a send_message node with remove_keyboard_after_press=false sends a message.
 * Used by the orchestrator to re-enter a flow branch when the user presses the button
 * outside of (or after) the originating session.
 *
 * @property string                     $id
 * @property string                     $tenant_id
 * @property string                     $contact_id
 * @property string                     $external_message_id
 * @property string                     $original_session_id  Session ID encoded in the button's callback_data
 * @property string                     $flow_definition_id   Snapshot definition at the time of sending
 * @property string                     $node_id              The send_message node inside the definition
 * @property string                     $button_id            The specific button UUID (one row per button)
 * @property \Illuminate\Support\Carbon $created_at
 */
final class PersistentInlineButton extends BaseModel
{
    use HasUlidPrimaryKey;

    public const string CREATED_AT = 'created_at';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'contact_id',
        'external_message_id',
        'original_session_id',
        'flow_definition_id',
        'node_id',
        'button_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
