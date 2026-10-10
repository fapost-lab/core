<?php

declare(strict_types=1);

namespace App\Filament\Resources\Media\Tables;

use App\Domains\Media\Contracts\MediaServiceInterface;
use App\Domains\Media\Models\MediaFile;
use App\Domains\Media\Models\MediaFolder;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Media\Enums\MediaKind;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Configures the table for the Filament media library page.
 *
 * Folder navigation is implemented as a {@see SelectFilter} (default = root) plus a
 * breadcrumb header on the page itself. Row + bulk actions delegate to
 * {@see MediaServiceInterface} so the validation surface matches the REST controllers.
 */
final class MediaFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')
                    ->label(__('media.fields.kind'))
                    ->badge()
                    ->color(static fn (MediaKind $state): string => match ($state) {
                        MediaKind::Image    => 'success',
                        MediaKind::Video    => 'warning',
                        MediaKind::Audio    => 'info',
                        MediaKind::Document => 'gray',
                        default             => 'gray',
                    }),
                TextColumn::make('name')
                    ->label(__('media.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('blob.size')
                    ->label(__('media.fields.size'))
                    ->formatStateUsing(static fn (?int $state): string => self::formatBytes((int)$state)),
                TextColumn::make('references_count')
                    ->label(__('media.fields.references'))
                    ->counts('references')
                    ->badge()
                    ->color(static fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('created_at')
                    ->label(__('media.fields.uploaded_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('folder_id')
                    ->label(__('media.fields.folder'))
                    ->options(fn (): array => self::folderOptions())
                    ->placeholder(__('media.filters.root_folder')),
                SelectFilter::make('kind')
                    ->label(__('media.fields.kind'))
                    ->options(static fn (): array => collect(MediaKind::cases())
                        ->mapWithKeys(static fn (MediaKind $k): array => [$k->value => __('media.kinds.' . $k->value)])
                        ->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('rename')
                    ->label(__('media.actions.rename'))
                    ->icon('heroicon-o-pencil-square')
                    ->visible(static fn (): bool => self::userCanManage())
                    ->schema(static fn (Schema $schema): Schema => $schema->components([
                        TextInput::make('name')
                            ->label(__('media.fields.name'))
                            ->required()
                            ->maxLength(255),
                    ]))
                    ->fillForm(static fn (MediaFile $record): array => ['name' => $record->name])
                    ->action(static function (array $data, MediaFile $record, MediaServiceInterface $service): void {
                        $service->rename($record, (string)$data['name']);
                    }),
                Action::make('move')
                    ->label(__('media.actions.move'))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->visible(static fn (): bool => self::userCanManage())
                    ->schema(static fn (Schema $schema): Schema => $schema->components([
                        Select::make('folder_id')
                            ->label(__('media.fields.folder'))
                            ->options(fn (): array => self::folderOptions())
                            ->placeholder(__('media.filters.root_folder')),
                    ]))
                    ->fillForm(static fn (MediaFile $record): array => ['folder_id' => $record->folder_id])
                    ->action(static function (array $data, MediaFile $record, MediaServiceInterface $service): void {
                        $folder = isset($data['folder_id']) && '' !== $data['folder_id']
                            ? MediaFolder::query()->find($data['folder_id'])
                            : null;
                        $service->move($record, $folder);
                    }),
                Action::make('references')
                    ->label(__('media.actions.references'))
                    ->icon('heroicon-o-link')
                    ->color('warning')
                    ->modalHeading(__('media.references.modal_heading'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('media.actions.close'))
                    ->modalContent(static fn (MediaFile $record) => view('media.references.modal', [
                        'references' => $record->references()->orderByDesc('created_at')->get(),
                    ]))
                    ->visible(static fn (MediaFile $record): bool => $record->references()->exists()),
                DeleteAction::make()
                    ->visible(
                        static fn (MediaFile $record): bool => self::userCanManage() && null === $record->deleted_at
                    ),
                RestoreAction::make()
                    ->visible(
                        static fn (MediaFile $record): bool => self::userCanManage() && null !== $record->deleted_at
                    ),
                ForceDeleteAction::make()
                    ->visible(
                        static fn (MediaFile $record): bool => self::userCanManage() && null !== $record->deleted_at
                    )
                    ->before(static function (MediaFile $record): void {
                        if ($record->references()->exists()) {
                            throw new RuntimeException(__('media.errors.has_references'));
                        }
                    })
                    // Through the service, so the blob and its stored object go with the last file.
                    ->using(static function (MediaFile $record, MediaServiceInterface $service): bool {
                        $service->forceDelete($record);

                        return true;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('move')
                        ->label(__('media.actions.move'))
                        ->icon('heroicon-o-arrow-right-circle')
                        ->color('gray')
                        ->visible(static fn (): bool => self::userCanManage())
                        ->schema(static fn (Schema $schema): Schema => $schema->components([
                            Select::make('folder_id')
                                ->label(__('media.fields.folder'))
                                ->options(static fn (): array => self::folderOptions())
                                ->placeholder(__('media.filters.root_folder')),
                        ]))
                        ->action(static function (array $data, Collection $records, MediaServiceInterface $service): void {
                            $folder = isset($data['folder_id']) && '' !== $data['folder_id']
                                ? MediaFolder::query()->find($data['folder_id'])
                                : null;

                            $records->each(static fn (MediaFile $record) => $service->move($record, $folder));

                            Notification::make()
                                ->title(__('media.notifications.files_moved', ['count' => $records->count()]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make()->visible(static fn (): bool => self::userCanManage()),
                ]),
            ])
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->withTrashed())
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(50)
            ->paginationPageOptions([25, 50, 100]);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int)floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return sprintf('%.1f %s', $bytes / (1024 ** $power), $units[$power]);
    }

    /**
     * @return array<string, string>
     */
    private static function folderOptions(): array
    {
        $tenantId = app(TenantContextInterface::class)->get()->getId();

        return MediaFolder::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('path_cache')
            ->pluck('path_cache', 'id')
            ->all();
    }

    private static function userCanManage(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(Permission::ManageMedia->value);
    }
}
