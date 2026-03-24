<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Contracts\TenantInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string                    $id
 * @property string                    $slug
 * @property string                    $schema_name
 * @property array<string, mixed>|null $config
 * @property TenantStatus              $status
 */
final class Tenant extends Model implements TenantInterface
{
    use HasUuids;

    protected $connection = 'landlord';

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
