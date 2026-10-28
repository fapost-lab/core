<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantAdminDirectoryInterface;
use App\Domains\Tenancy\Contracts\TenantDeletionRequestRepositoryInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Enums\DeletionRequestOutcome;
use App\Domains\Tenancy\Events\WorkspaceDeletionCancelled;
use App\Domains\Tenancy\Events\WorkspaceDeletionScheduled;
use App\Domains\Tenancy\Models\TenantDeletionRequest;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Fapost\Foundation\Tenancy\Contracts\TenantSelfDeletionPolicyInterface;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * An admin of a tenant deleting the workspace from its settings, 24 hours after asking and unless an admin cancels.
 *
 * Asking needs three yeses: the installation resolves tenants by host (in `single` mode the only tenant would be
 * lost), the operator's policy ({@see TenantSelfDeletionPolicyInterface}, no on an installation without an operator
 * package) and the person being an administrator of the tenant, as the staff domain answers
 * ({@see TenantAdminDirectoryInterface}; the platform support user never is). Cancelling needs only the last: whoever
 * could cancel a request may do so after the policy changed.
 *
 * The request is a landlord record, not a job: the workspace works as usual until `starts_at`, when
 * `tenants:start-deletions` queues the removal. Both steps announce themselves with an event, which the staff domain
 * turns into a mail to every admin.
 */
final readonly class WorkspaceDeletion
{
    public function __construct(
        private TenantSelfDeletionPolicyInterface $policy,
        private TenantAdminDirectoryInterface $admins,
        private TenantDeletionRequestRepositoryInterface $requests,
        private Dispatcher $events,
    ) {
    }

    public function mayRequest(string $userId, TenantInterface $tenant): bool
    {
        return TenancyResolutionMode::Host === TenancyResolutionMode::tryFrom((string) config('tenancy.resolution'))
            && $this->policy->allows($tenant->getId())
            && null !== $this->admins->find($userId);
    }

    public function mayCancel(string $userId): bool
    {
        return null !== $this->admins->find($userId);
    }

    public function active(TenantInterface $tenant): ?TenantDeletionRequest
    {
        return $this->requests->active($tenant->getId());
    }

    /**
     * Whether the typed text names this workspace: its slug, as written, without surrounding spaces.
     */
    public function confirms(TenantInterface $tenant, string $typed): bool
    {
        return hash_equals($tenant->getSlug(), mb_trim($typed));
    }

    /**
     * Records the request and announces it. The caller has already checked {@see self::mayRequest()} and
     * {@see self::confirms()}.
     */
    public function request(string $userId, TenantInterface $tenant): DeletionRequestOutcome
    {
        $by = $this->admins->find($userId);

        if (null === $by) {
            return DeletionRequestOutcome::AlreadyScheduled;
        }

        $now = CarbonImmutable::now('UTC');

        // The request and the queued notice to every admin commit together: a queue that refuses the notice rolls
        // the request back, so a workspace is never scheduled for deletion without its admins being told.
        return $this->requests->transaction(function () use ($tenant, $by, $now): DeletionRequestOutcome {
            $request = $this->requests->schedule($tenant->getId(), $by, $now, $now->addHours(TenantDeletionRequest::DELAY_HOURS));

            if (null === $request) {
                return DeletionRequestOutcome::AlreadyScheduled;
            }

            $this->events->dispatch(new WorkspaceDeletionScheduled(
                $tenant->getId(),
                $tenant->getSlug(),
                $by->name,
                $by->email,
                $this->at($request->requested_at),
                $this->at($request->starts_at),
            ));

            return DeletionRequestOutcome::Scheduled;
        });
    }

    /**
     * Cancels the active request and announces it. False when there is none, or it started or was cancelled meanwhile.
     */
    public function cancel(string $userId, TenantInterface $tenant): bool
    {
        $by      = $this->admins->find($userId);
        $request = $this->requests->active($tenant->getId());

        if (null === $by || null === $request) {
            return false;
        }

        $now = CarbonImmutable::now('UTC');

        if (! $this->requests->cancel($request->getKey(), $by, $now)) {
            return false;
        }

        $this->events->dispatch(new WorkspaceDeletionCancelled(
            $tenant->getId(),
            $tenant->getSlug(),
            $request->requested_by_name,
            $request->requested_by_email,
            $this->at($request->requested_at),
            $this->at($request->starts_at),
            $by->name,
            $by->email,
            $this->at($now),
        ));

        return true;
    }

    private function at(DateTimeInterface $time): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'));
    }
}
