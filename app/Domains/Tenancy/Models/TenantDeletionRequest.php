<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A request to delete a workspace, scheduled 24 hours ahead. The requester and the canceller are kept as a snapshot
 * (name and email), since a mail names them and the user may be gone by the time it is read. Landlord table owned by
 * Tenancy.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $requested_by_user_id
 * @property string $requested_by_name
 * @property string $requested_by_email
 * @property \Illuminate\Support\Carbon $requested_at
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancelled_by_user_id
 * @property string|null $cancelled_by_name
 * @property string|null $cancelled_by_email
 * @property \Illuminate\Support\Carbon|null $started_at
 */
final class TenantDeletionRequest extends Model
{
    use HasUlidPrimaryKey;

    /** How long a request waits before the removal starts. */
    public const int DELAY_HOURS = 24;

    /** @var bool */
    public $timestamps = false;

    /** @var string */
    protected $connection = 'landlord';

    /** @var string */
    protected $table = 'tenant_deletion_requests';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'requested_by_user_id',
        'requested_by_name',
        'requested_by_email',
        'requested_at',
        'starts_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'starts_at'    => 'datetime',
            'cancelled_at' => 'datetime',
            'started_at'   => 'datetime',
        ];
    }
}
