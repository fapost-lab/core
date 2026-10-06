<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Support\TenantHost;
use DateTimeImmutable;
use DateTimeZone;
use Fapost\Foundation\Tenancy\Contracts\TenantDirectoryInterface;
use Fapost\Foundation\Tenancy\DTO\TenantList;
use Fapost\Foundation\Tenancy\DTO\TenantListQuery;
use Fapost\Foundation\Tenancy\DTO\TenantSummary;
use Fapost\Foundation\Tenancy\Enums\TenantSort;
use Fapost\Foundation\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Core's implementation of the public, read-only tenant directory contract.
 *
 * Reads the landlord `tenants` table through the Tenancy domain and exposes only what an operator
 * needs: no schema name, no `config`. A package that depends only on Foundation never sees the model.
 */
final readonly class CoreTenantDirectory implements TenantDirectoryInterface
{
    public function list(TenantListQuery $query): TenantList
    {
        $builder = Tenant::on('landlord');

        if (null !== $query->search && '' !== $query->search) {
            $needle = addcslashes(mb_strtolower($query->search), '\\%_');
            $builder->whereRaw('LOWER(slug) LIKE ? ESCAPE \'\\\'', ['%' . $needle . '%']);
        }

        if ($query->status instanceof TenantStatus) {
            $builder->where('status', $query->status->value);
        }

        if (null !== $query->onlyIds) {
            $builder->whereIn('id', $this->normalizeIds($query->onlyIds));
        }

        $exceptIds = $this->normalizeIds($query->exceptIds ?? []);

        if ([] !== $exceptIds) {
            $builder->whereNotIn('id', $exceptIds);
        }

        $total = $builder->clone()->toBase()->getCountForPagination();

        $rows = $this->sorted($builder, $query)
            ->forPage($query->page, $query->perPage)
            ->get();

        return new TenantList(
            items: $rows->map(fn (Tenant $tenant): TenantSummary => $this->summarize($tenant))->values()->all(),
            total: $total,
            page: $query->page,
            perPage: $query->perPage,
        );
    }

    public function find(string $id): ?TenantSummary
    {
        $normalized = $this->normalizeId($id);

        if (null === $normalized) {
            return null;
        }

        $tenant = Tenant::on('landlord')->find($normalized);

        return $tenant instanceof Tenant ? $this->summarize($tenant) : null;
    }

    public function findMany(array $ids): array
    {
        $ids = $this->normalizeIds($ids);

        if ([] === $ids) {
            return [];
        }

        $summaries = [];

        foreach (Tenant::on('landlord')->whereIn('id', $ids)->get() as $tenant) {
            $summaries[$tenant->getId()] = $this->summarize($tenant);
        }

        return $summaries;
    }

    /**
     * @param  Builder<Tenant>  $builder
     * @return Builder<Tenant>
     */
    private function sorted(Builder $builder, TenantListQuery $query): Builder
    {
        $column = match ($query->sort) {
            TenantSort::Slug      => 'slug',
            TenantSort::CreatedAt => 'created_at',
        };
        $direction = $query->descending ? 'desc' : 'asc';

        if (TenantSort::CreatedAt === $query->sort) {
            // Rows without a creation time go last in both directions, on every database.
            $builder->orderByRaw('created_at IS NULL');
        }

        return $builder->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * Tenant ids are lowercase RFC 4122 ULIDs; any other form names no tenant, and must not
     * reach a `uuid` column where Postgres would reject it.
     */
    private function normalizeId(string $id): ?string
    {
        $lower = mb_strtolower($id);

        return Str::isUuid($lower) ? $lower : null;
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function normalizeIds(array $ids): array
    {
        $valid = [];

        foreach ($ids as $id) {
            $normalized = $this->normalizeId($id);

            if (null !== $normalized) {
                $valid[$normalized] = $normalized;
            }
        }

        return array_values($valid);
    }

    private function summarize(Tenant $tenant): TenantSummary
    {
        $createdAt = $tenant->created_at;

        return new TenantSummary(
            id: $tenant->getId(),
            slug: $tenant->getSlug(),
            status: TenantStatus::from($tenant->status->value),
            createdAt: null === $createdAt
                ? null
                : new DateTimeImmutable('@' . $createdAt->getTimestamp())->setTimezone(new DateTimeZone('UTC')),
            url: mb_rtrim(TenantHost::urlFor($tenant, '/'), '/') . '/',
            loginUrl: TenantHost::urlFor($tenant, TenantHost::ADMIN_LOGIN_PATH),
        );
    }
}
