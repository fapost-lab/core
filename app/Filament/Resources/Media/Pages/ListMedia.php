<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Pages;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Contracts\MediaUploaderInterface;
use App\Domains\Media\Enums\MediaSource;
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
    protected static string $resource        = MediaResource::class;
    protected string        $view            = 'filament.resources.media.pages.list-media';

    // ── Folder navigation ────────────────────────────────────────────────────

    public function navigateToFolder(?string $folderId): void
    {
        $this->currentFolderId = $folderId;
        $this->resetTable();
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
     * @return array<int, array{id:string,name:string}>
     */
    public function getCurrentSubfolders(): array
    {
        $tenantId = app(TenantContextInterface::class)->get()->getId();

        return MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->where('parent_id', $this->currentFolderId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (MediaFolder $f): array => ['id' => $f->id, 'name' => $f->name])
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
            'subfolders'        => $this->getCurrentSubfolders(),
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
    private function folderOptions(): array
    {
        $tenantId = app(TenantContextInterface::class)->get()->getId();

        return MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('path_cache')
            ->pluck('path_cache', 'id')
            ->all();
    }
}
