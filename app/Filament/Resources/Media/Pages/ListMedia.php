<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Resources\Media\MediaResource;
use App\Filament\Resources\Media\Tables\MediaFilesTable;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

/**
 * Media library entry page with inline folder navigation.
 *
 * Folder browsing is driven by the Livewire `$currentFolderId` property.
 * Header actions cover upload (multi-file) and create-folder.
 */
final class ListMedia extends ListRecords
{
    /** Currently browsed folder id; null = root. Persisted in URL so back-navigation works. */
    #[Url(as: 'folder', except: '')]
    public ?string          $currentFolderId = null;

    // ── Delete-folder dialog state ────────────────────────────────────────────

    public ?string $deleteFolderId       = null;
    public string  $deleteFolderName     = '';
    public int     $deleteFolderFiles    = 0;
    public int     $deleteFolderChildren = 0;
    public string  $deleteMoveToId       = '';

    // ── Rename-folder dialog state ────────────────────────────────────────────

    public ?string $renameFolderId   = null;
    public string  $renameFolderName = '';

    protected static string $resource = MediaResource::class;
    protected string        $view     = 'filament.resources.media.pages.list-media';

    // ── Folder navigation ────────────────────────────────────────────────────

    public function navigateToFolder(?string $folderId): void
    {
        $this->currentFolderId = $folderId;
        $this->resetTable();
    }

    // ── Delete folder ─────────────────────────────────────────────────────────

    /**
     * Populates dialog state and dispatches the Alpine open event.
     */
    public function initDeleteFolder(string $folderId): void
    {
        if ( ! $this->canManage()) {
            return;
        }

        $folder = MediaFolder::query()->find($folderId);

        if (null === $folder) {
            return;
        }

        $this->deleteFolderId       = $folderId;
        $this->deleteFolderName     = $folder->name;
        $this->deleteFolderFiles    = MediaFile::query()->where('folder_id', $folderId)->count();
        $this->deleteFolderChildren = MediaFolder::query()->where('parent_id', $folderId)->count();
        $this->deleteMoveToId       = '';

        $this->dispatch('open-delete-folder-dialog');
    }

    /**
     * Moves direct files and direct subfolders to the chosen target (or root), then deletes the folder.
     *
     * Subfolders are re-parented via moveFolder() so their descendants' path_cache is updated correctly.
     */
    public function executeDeleteFolder(MediaServiceInterface $service): void
    {
        if ( ! $this->canManage() || null === $this->deleteFolderId) {
            return;
        }

        $folder = MediaFolder::query()->find($this->deleteFolderId);

        if (null === $folder) {
            $this->deleteFolderId = null;

            return;
        }

        $target = '' !== $this->deleteMoveToId
            ? MediaFolder::query()->find($this->deleteMoveToId)
            : null;

        // Move direct files
        MediaFile::query()
            ->where('folder_id', $folder->id)
            ->each(static fn (MediaFile $f) => $service->move($f, $target));

        // Re-parent direct subfolders so they are not orphaned
        MediaFolder::query()
            ->where('parent_id', $folder->id)
            ->each(static fn (MediaFolder $child) => $service->moveFolder($child, $target));

        if ($this->currentFolderId === $folder->id) {
            $this->currentFolderId = $target?->id ?? $folder->parent_id;
        }

        $service->deleteFolder($folder);

        $this->deleteFolderId = null;

        $this->resetTable();

        Notification::make()
            ->title(__('media.notifications.folder_deleted'))
            ->success()
            ->send();
    }

    /**
     * Folder options for the move-to select — excludes the deleted folder and all its descendants
     * (moving a folder into its own subtree would corrupt the tree).
     *
     * @return array<string, string>
     */
    public function folderOptionsForDelete(): array
    {
        if (null === $this->deleteFolderId) {
            return $this->folderOptions();
        }

        $folder = MediaFolder::query()->find($this->deleteFolderId);

        if (null === $folder) {
            return $this->folderOptions();
        }

        $tenantId = app(TenantContextInterface::class)->get()->getId();

        return MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->where('id', '!=', $this->deleteFolderId)
            ->where('path_cache', 'not like', $folder->path_cache . '/%')
            ->orderBy('path_cache')
            ->pluck('path_cache', 'id')
            ->all();
    }

    // ── Rename folder ─────────────────────────────────────────────────────────

    /**
     * Opens the rename dialog pre-filled with the current folder name.
     */
    public function initRenameFolder(string $folderId): void
    {
        if ( ! $this->canManage()) {
            return;
        }

        $folder = MediaFolder::query()->find($folderId);

        if (null === $folder) {
            return;
        }

        $this->renameFolderId   = $folderId;
        $this->renameFolderName = $folder->name;

        $this->dispatch('open-rename-folder-dialog');
    }

    /**
     * Persists the new folder name via the service layer.
     */
    public function executeRenameFolder(MediaServiceInterface $service): void
    {
        if ( ! $this->canManage() || null === $this->renameFolderId) {
            return;
        }

        $name = mb_trim($this->renameFolderName);

        if ('' === $name) {
            return;
        }

        $folder = MediaFolder::query()->find($this->renameFolderId);

        if (null === $folder) {
            $this->renameFolderId = null;

            return;
        }

        $service->renameFolder($folder, $name);

        $this->renameFolderId = null;

        Notification::make()
            ->title(__('media.notifications.folder_renamed'))
            ->success()
            ->send();
    }

    public function table(Table $table): Table
    {
        return MediaFilesTable::configure($table)
            ->modifyQueryUsing(function (Builder $query): Builder {
                $tenantId = app(TenantContextInterface::class)->get()->getId();

                return $query
                    ->where('tenant_id', $tenantId)
                    ->where('folder_id', $this->currentFolderId)
                    ->withTrashed();
            });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Flat list of all tenant folders ordered by path, with computed depth for indentation.
     *
     * @return array<int, array{id:string,name:string,depth:int}>
     */
    public function getFolderTree(): array
    {
        $tenantId = app(TenantContextInterface::class)->get()->getId();

        return MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('path_cache')
            ->get(['id', 'name', 'path_cache'])
            ->map(static fn (MediaFolder $f): array => [
                'id'    => $f->id,
                'name'  => $f->name,
                'depth' => max(0, mb_substr_count($f->path_cache, '/') - 1),
            ])
            ->all();
    }

    /**
     * @return array<int, array{id:string,name:string}>
     */
    public function getFolderBreadcrumbs(): array
    {
        if (null === $this->currentFolderId) {
            return [];
        }

        $crumbs = [];
        $folder = MediaFolder::query()->find($this->currentFolderId);

        while (null !== $folder) {
            array_unshift($crumbs, ['id' => $folder->id, 'name' => $folder->name]);
            $folder = null !== $folder->parent_id
                ? MediaFolder::query()->find($folder->parent_id)
                : null;
        }

        return $crumbs;
    }

    protected function getHeaderActions(): array
    {
        $canManage = $this->canManage();

        return [
            Action::make('upload')
                ->label(__('media.actions.upload'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible($canManage)
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    FileUpload::make('files')
                        ->label(__('media.actions.upload'))
                        ->multiple()
                        ->disk('local')
                        ->maxSize((int)max(1, (int)config('media.max_size_bytes', 100 * 1024 * 1024) / 1024))
                        ->preserveFilenames()
                        ->required()
                        ->validationAttribute(__('media.fields.file'))
                        ->validationMessages([
                            'max' => __('media.errors.file_too_large', [
                                'max' => round(config('media.max_size_bytes', 100 * 1024 * 1024) / 1024 / 1024),
                            ]),
                        ]),
                    Select::make('folder_id')
                        ->label(__('media.fields.folder'))
                        ->options(fn (): array => $this->folderOptions())
                        ->default(fn (): ?string => $this->currentFolderId)
                        ->placeholder(__('media.filters.root_folder')),
                ]))
                ->action(function (array $data, MediaUploaderInterface $uploader): void {
                    $folder = isset($data['folder_id']) && '' !== $data['folder_id']
                        ? MediaFolder::query()->find($data['folder_id'])
                        : null;

                    // Filament v5 FileUpload returns stored temporary paths (strings),
                    // not UploadedFile instances. Reconstruct from the local disk.
                    $paths   = array_filter((array)($data['files'] ?? []), 'is_string');
                    $created = 0;

                    foreach ($paths as $storedPath) {
                        $fullPath = Storage::disk('local')->path($storedPath);
                        if ( ! file_exists($fullPath)) {
                            continue;
                        }

                        $originalName = basename($storedPath);
                        $upload       = new \Illuminate\Http\UploadedFile($fullPath, $originalName, null, null, true);

                        $uploader->uploadFromUploadedFile(
                            file: $upload,
                            folder: $folder,
                            name: $originalName,
                            source: MediaSource::Upload,
                            uploadedBy: (string)Auth::id(),
                        );

                        Storage::disk('local')->delete($storedPath);
                        $created++;
                    }

                    Notification::make()
                        ->title(__('media.notifications.uploaded', ['count' => $created]))
                        ->success()
                        ->send();

                    $this->resetTable();
                }),

            Action::make('create_folder')
                ->label(__('media.actions.create_folder'))
                ->icon('heroicon-o-folder-plus')
                ->visible($canManage)
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    TextInput::make('name')
                        ->label(__('media.fields.name'))
                        ->required()
                        ->maxLength((int)config('media.folder.name_max_chars', 255)),
                    Select::make('parent_id')
                        ->label(__('media.fields.parent_folder'))
                        ->options(fn (): array => $this->folderOptions())
                        ->default(fn (): ?string => $this->currentFolderId)
                        ->placeholder(__('media.filters.root_folder')),
                ]))
                ->action(function (array $data, MediaServiceInterface $service): void {
                    $parent = isset($data['parent_id']) && '' !== $data['parent_id']
                        ? MediaFolder::query()->find($data['parent_id'])
                        : null;

                    $service->createFolder(
                        name: (string)$data['name'],
                        parent: $parent,
                        createdBy: (string)Auth::id(),
                    );

                    Notification::make()
                        ->title(__('media.notifications.folder_created'))
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getViewData(): array
    {
        return [
            'folderTree'        => $this->getFolderTree(),
            'folderBreadcrumbs' => $this->getFolderBreadcrumbs(),
        ];
    }

    // ── Table ────────────────────────────────────────────────────────────────

    private function canManage(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(Permission::ManageMedia->value);
    }

    // ── Header actions ───────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function folderOptions(?string $exclude = null): array
    {
        $tenantId = app(TenantContextInterface::class)->get()->getId();

        return MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->when(null !== $exclude, static fn ($q) => $q->where('id', '!=', $exclude))
            ->orderBy('path_cache')
            ->pluck('path_cache', 'id')
            ->all();
    }
}
