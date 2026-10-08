<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Contracts\TenantInterface;
use Fapost\Support\Concerns\HasUlidPrimaryKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string       $id
 * @property string       $slug
 * @property string       $schema_name
 * @property TenantStatus $status
 * @property array<array-key, mixed> $config
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static Builder<static>|Tenant newModelQuery()
 * @method static Builder<static>|Tenant newQuery()
 * @method static Builder<static>|Tenant query()
 * @method static Builder<static>|Tenant whereConfig($value)
 * @method static Builder<static>|Tenant whereCreatedAt($value)
 * @method static Builder<static>|Tenant whereId($value)
 * @method static Builder<static>|Tenant whereSchemaName($value)
 * @method static Builder<static>|Tenant whereSlug($value)
 * @method static Builder<static>|Tenant whereStatus($value)
 * @method static Builder<static>|Tenant whereUpdatedAt($value)
 * @mixin \Eloquent
 */
final class Tenant extends Model implements TenantInterface
{
    use HasUlidPrimaryKey;

    /** @var string */
    protected $connection = 'landlord';

    /** @var string */
    protected $table = 'tenants';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'schema_name',
        'status',
        'config',
    ];

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getSchemaName(): string
    {
        return $this->schema_name;
    }

    public function isActive(): bool
    {
        return TenantStatus::Active === $this->status;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'array',
            'status' => TenantStatus::class,
        ];
    }
}
