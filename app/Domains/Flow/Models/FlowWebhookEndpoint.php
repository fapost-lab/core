<?php

declare(strict_types=1);

namespace App\Domains\Flow\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;

/**
 * The inbound address of a webhook trigger: the token of `/hooks/flow/{token}` and the secret its sender signs with.
 *
 * One per trigger id (unique `trigger_id`; the row has its own ULID key), the trigger's id in the flow's draft and
 * definitions and its `flow_triggers` primary key once published; made before the trigger is live, and bound to the
 * flow it was made for (`flow_id`). The secret is encrypted with the application key and
 * never leaves the server except in the answer to the request that generated it.
 *
 * @property string                          $id
 * @property string                          $tenant_id
 * @property string                          $flow_id
 * @property string                          $trigger_id
 * @property string                          $token
 * @property string|null                     $secret
 * @property \Illuminate\Support\Carbon|null $secret_rotated_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
final class FlowWebhookEndpoint extends BaseModel
{
    use HasUlidPrimaryKey;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'flow_id',
        'trigger_id',
        'token',
        'secret',
        'secret_rotated_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'secret',
    ];

    public function hasSecret(): bool
    {
        return null !== $this->secret && '' !== $this->secret;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret'            => 'encrypted',
            'secret_rotated_at' => 'datetime',
        ];
    }
}
