<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowGroups\Tables;

use App\Domains\Flow\Exceptions\FlowGroupNotEmptyException;
use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Flow\Services\FlowGroupService;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

final class FlowGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('assistant.flow_groups.fields.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('drafts_count')
                    ->label(__('assistant.flow_groups.fields.flows_count'))
                    ->counts('drafts')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    ->before(function (FlowGroup $record, DeleteAction $action): void {
                        if ($record->drafts()->exists()) {
                            Notification::make()
                                ->danger()
                                ->title(__('assistant.flow_groups.delete_guard.title'))
                                ->body(__('assistant.flow_groups.delete_guard.body'))
                                ->send();

                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->using(function (Collection $records, DeleteBulkAction $action): void {
                        $groups  = app(FlowGroupService::class);
                        $blocked = 0;

                        foreach ($records as $record) {
                            try {
                                $groups->delete($record);
                            } catch (FlowGroupNotEmptyException) {
                                ++$blocked;
                            }
                        }

                        if ($blocked > 0) {
                            Notification::make()
                                ->warning()
                                ->title(__('assistant.flow_groups.delete_guard.title'))
                                ->body(__('assistant.flow_groups.delete_guard.skipped', ['count' => $blocked]))
                                ->send();
                        }

                        // Nothing was deleted: stop before Filament reports a success.
                        if ($blocked === $records->count()) {
                            $action->halt();
                        }
                    }),
            ]);
    }
}
