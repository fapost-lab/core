<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Repositories;

use App\Domains\Tenancy\Contracts\TenantDeletionRequestRepositoryInterface;
use App\Domains\Tenancy\Models\TenantDeletionRequest;
use App\Domains\Tenancy\ValueObjects\TenantAdmin;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

final class TenantDeletionRequestRepository implements TenantDeletionRequestRepositoryInterface
{
    public function active(string $tenantId): ?TenantDeletionRequest
    {
        return $this->pending()->where('tenant_id', $tenantId)->first();
    }

    public function find(string $id): ?TenantDeletionRequest
    {
        return TenantDeletionRequest::on('landlord')->find($id);
    }

    public function schedule(string $tenantId, TenantAdmin $by, CarbonInterface $now, CarbonInterface $startsAt): ?TenantDeletionRequest
    {
        if (null !== $this->active($tenantId)) {
            return null;
        }

        try {
            // A transaction of its own (a savepoint inside the caller's): a unique violation must not abort the caller's.
            return TenantDeletionRequest::on('landlord')->getConnection()->transaction(
                static fn (): TenantDeletionRequest => TenantDeletionRequest::on('landlord')->create([
                    'tenant_id'            => $tenantId,
                    'requested_by_user_id' => $by->id,
                    'requested_by_name'    => $by->name,
                    'requested_by_email'   => $by->email,
                    'requested_at'         => $now,
                    'starts_at'            => $startsAt,
                ]),
            );
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    public function transaction(callable $work): mixed
    {
        return TenantDeletionRequest::on('landlord')->getConnection()->transaction($work);
    }

    public function cancel(string $id, TenantAdmin $by, CarbonInterface $now): bool
    {
        return 1 === $this->pending()->whereKey($id)->update([
            'cancelled_at'         => $now,
            'cancelled_by_user_id' => $by->id,
            'cancelled_by_name'    => $by->name,
            'cancelled_by_email'   => $by->email,
        ]);
    }

    public function due(CarbonInterface $now): array
    {
        return array_values($this->pending()->where('starts_at', '<=', $now)->orderBy('starts_at')->get()->all());
    }

    public function claim(string $id, CarbonInterface $now): bool
    {
        $request = $this->find($id);

        if (null === $request || null !== $request->cancelled_at) {
            return false;
        }

        if (null === $request->started_at) {
            // Conditional on still being active: a cancel that won the race leaves no row to change.
            if (1 !== $this->pending()->whereKey($id)->update(['started_at' => $now])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return Builder<TenantDeletionRequest>
     */
    private function pending(): Builder
    {
        return TenantDeletionRequest::on('landlord')->whereNull('cancelled_at')->whereNull('started_at');
    }
}
