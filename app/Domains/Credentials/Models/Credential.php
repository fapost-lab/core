<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Models;

use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Fapost\Support\Models\BaseModel;
use Illuminate\Support\Carbon;

/**
 * A saved credential of one tenant: the non-secret fields of its type (`settings`), the hosts it may be sent to
 * (`allowed_hosts`) and the secret fields, encrypted at rest (`secret`).
 *
 * The secret never leaves the model by accident: the attribute is hidden from `toArray()` and JSON. It is read
 * only through {@see \App\Domains\Credentials\EloquentCredentialStore} (to apply it to a call) and written only
 * through {@see \App\Domains\Credentials\CredentialWriter}.
 *
 * @property string                       $id
 * @property string                       $tenant_id
 * @property string                       $name
 * @property string                       $type
 * @property array<string, string|null>   $settings
 * @property list<string>                 $allowed_hosts
 * @property array<string, string>        $secret
 * @property Carbon                       $secret_changed_at
 * @property Carbon|null                  $created_at
 * @property Carbon|null                  $updated_at
 */
final class Credential extends BaseModel
{
    use HasUlidPrimaryKey;

    /** @var string */
    protected $table = 'credentials';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'settings',
        'allowed_hosts',
        'secret',
        'secret_changed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret'            => 'encrypted:array',
            'settings'          => 'array',
            'allowed_hosts'     => 'array',
            'secret_changed_at' => 'datetime',
        ];
    }
}
