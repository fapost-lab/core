<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\TenantDeletionRequest;
use App\Domains\Tenancy\Services\WorkspaceDeletion;

/**
 * What the console shows about deleting the workspace: the section on the settings page and the banner above every
 * page while a deletion is scheduled. Both go to administrators only; each time is an ISO 8601 UTC string the page
 * writes out and labels as UTC.
 */
final readonly class WorkspaceDeletionPresenter
{
    public function __construct(
        private WorkspaceDeletion $deletion,
        private TenantContextInterface $tenants,
    ) {
    }

    /**
     * The settings page's section, or null when there is nothing to offer this person: not an admin, or no request
     * scheduled and none may be made.
     *
     * @return array{slug: string, canRequest: bool, requestUrl: string, cancelUrl: string, scheduled: array{startsAt: string, requestedAt: string, requestedBy: string}|null}|null
     */
    public function section(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        $tenant     = $this->tenants->get();
        $id         = (string) $user->getKey();
        $canRequest = $this->deletion->mayRequest($id, $tenant);
        $scheduled  = $this->deletion->mayCancel($id) ? $this->deletion->active($tenant) : null;

        if (! $canRequest && null === $scheduled) {
            return null;
        }

        return [
            'slug'       => $tenant->getSlug(),
            'canRequest' => $canRequest,
            'requestUrl' => route('console.admin.tenant-settings.delete', [], false),
            'cancelUrl'  => route('console.admin.tenant-settings.delete.cancel', [], false),
            'scheduled'  => null === $scheduled ? null : $this->scheduled($scheduled),
        ];
    }

    /**
     * The banner for an administrator while a deletion is scheduled; null otherwise.
     *
     * @return array{startsAt: string, requestedAt: string, requestedBy: string, cancelUrl: string}|null
     */
    public function banner(?User $user): ?array
    {
        if (! $user instanceof User || ! $this->deletion->mayCancel((string) $user->getKey())) {
            return null;
        }

        $scheduled = $this->deletion->active($this->tenants->get());

        return null === $scheduled
            ? null
            : [...$this->scheduled($scheduled), 'cancelUrl' => route('console.admin.tenant-settings.delete.cancel', [], false)];
    }

    /**
     * @return array{startsAt: string, requestedAt: string, requestedBy: string}
     */
    private function scheduled(TenantDeletionRequest $request): array
    {
        return [
            'startsAt'    => $request->starts_at->toIso8601ZuluString(),
            'requestedAt' => $request->requested_at->toIso8601ZuluString(),
            'requestedBy' => $request->requested_by_name,
        ];
    }
}
