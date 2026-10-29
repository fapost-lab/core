<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Enums\DeletionRequestOutcome;
use App\Domains\Tenancy\Services\WorkspaceDeletion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CancelWorkspaceDeletionRequest;
use App\Http\Requests\Admin\RequestWorkspaceDeletionRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * An admin asking to delete the workspace from the tenant's settings, and any admin cancelling it. Authorization and
 * the typed slug are the requests'; the service records the request and announces it. Nobody is signed out: the
 * workspace works as usual until the removal starts, 24 hours later.
 */
final class WorkspaceDeletionController extends Controller
{
    public function store(RequestWorkspaceDeletionRequest $request, WorkspaceDeletion $deletion, TenantContextInterface $context): RedirectResponse
    {
        $outcome = $deletion->request((string) $request->user()?->getKey(), $context->get());

        if (DeletionRequestOutcome::AlreadyScheduled === $outcome) {
            Inertia::flash('error', trans('console.tenant_settings.delete.already_scheduled'));
        } else {
            Inertia::flash('success', trans('console.tenant_settings.delete.scheduled'));
        }

        return redirect()->back(fallback: route('console.admin.tenant-settings.edit', [], false));
    }

    public function cancel(CancelWorkspaceDeletionRequest $request, WorkspaceDeletion $deletion, TenantContextInterface $context): RedirectResponse
    {
        if ($deletion->cancel((string) $request->user()?->getKey(), $context->get())) {
            Inertia::flash('success', trans('console.tenant_settings.delete.cancelled'));
        } else {
            Inertia::flash('error', trans('console.tenant_settings.delete.nothing_to_cancel'));
        }

        return redirect()->back(fallback: route('console.admin.tenant-settings.edit', [], false));
    }
}
