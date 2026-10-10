<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Media\Exceptions\MediaFolderRuleException;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Media\Services\MediaFolderService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteMediaFolderRequest;
use App\Http\Requests\Admin\MediaFolderRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * The folders of the admin media library. The tree's rules (depth, no move into the own subtree, a deleted folder's
 * contents moved first) are {@see MediaFolderService}'s; a refusal comes back to the dialog as a field error.
 */
final class MediaFolderController extends Controller
{
    public function __construct(
        private readonly MediaFolderService $folders,
    ) {
    }

    public function store(MediaFolderRequest $request): RedirectResponse
    {
        Gate::authorize('create', MediaFolder::class);

        try {
            $this->folders->create(
                name: $request->name(),
                parent: $this->folders->resolve($request->parentId(), 'parent_id'),
                createdBy: (string) $request->user()?->getAuthIdentifier(),
            );
        } catch (MediaFolderRuleException $exception) {
            throw $exception->toValidationException();
        }

        Inertia::flash('success', __('media.notifications.folder_created'));

        return redirect()->back(fallback: $this->index());
    }

    /**
     * Renames the folder; its descendants' paths follow.
     */
    public function update(MediaFolderRequest $request, string $folder): RedirectResponse
    {
        $record = $this->folders->find($folder);

        Gate::authorize('update', $record);

        $this->folders->rename($record, $request->name());

        Inertia::flash('success', __('media.notifications.folder_renamed'));

        return redirect()->back(fallback: $this->index());
    }

    /**
     * Moves the folder's files and subfolders into the chosen folder (or the root) and deletes it. The list stays on
     * the folder it had open, unless that was the deleted folder or inside it: then it opens the folder that received
     * the contents, so it never shows a folder that is gone.
     */
    public function destroy(DeleteMediaFolderRequest $request, string $folder): RedirectResponse
    {
        $record = $this->folders->find($folder);

        Gate::authorize('delete', $record);

        $open = $this->openFolder($request->openFolder());

        try {
            $target = $this->folders->resolve($request->moveTo(), 'move_to');
            $this->folders->deleteMovingContents($record, $target);
        } catch (MediaFolderRuleException $exception) {
            throw $exception->toValidationException();
        }

        Inertia::flash('success', __('media.notifications.folder_deleted'));

        if (null === $open || ! $this->folders->isInSubtree($open, $record)) {
            return redirect()->back(fallback: $this->index());
        }

        return redirect()->to($this->index(null === $target ? [] : ['filter' => ['folder' => $target->id]]));
    }

    /**
     * The folder the list had open; one that is not (or no longer) in the tenant counts as none.
     */
    private function openFolder(?string $id): ?MediaFolder
    {
        try {
            return $this->folders->resolve($id, 'open_folder');
        } catch (MediaFolderRuleException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function index(array $parameters = []): string
    {
        return route('filament.admin.resources.media.index', $parameters, false);
    }
}
