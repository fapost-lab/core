<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ResendActivationService;
use App\Domains\Staff\Services\UserService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('staff.users.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('staff.users.table.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('staff.users.table.phone'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('staff.users.table.status'))
                    ->badge()
                    ->formatStateUsing(function ($state): string {
                        $status = $state instanceof UserStatus
                            ? $state
                            : UserStatus::tryFrom((string)$state);

                        return $status instanceof UserStatus
                            ? __('staff.users.status.' . $status->value)
                            : (string)$state;
                    })
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label(__('staff.users.table.active'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('roles.name')
                    ->badge()
                    ->separator(', ')
                    ->label(__('staff.users.table.roles')),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('resendActivation')
                    ->label(__('staff.users.actions.resend_activation'))
                    ->icon('heroicon-o-envelope')
                    ->visible(function (User $record): bool {
                        $actor = Auth::user();

                        return UserStatus::Pending === $record->status
                               && $actor instanceof User
                               && $actor->can('resendActivation', $record);
                    })
                    ->action(function (User $record, ResendActivationService $resend): void {
                        $resend->resend($record);
                    }),
                Action::make('deactivate')
                    ->label(__('staff.users.actions.deactivate'))
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(function (User $record): bool {
                        /** @var User $actor */
                        $actor = Auth::user();

                        return $record->is_active
                               && ! $actor->is($record)
                               && $actor->can('deactivate', $record);
                    })
                    ->action(function (User $record, UserService $userService): void {
                        /** @var User $actor */
                        $actor = Auth::user();
                        $userService->deactivate($actor, $record);
                    }),
                Action::make('activate')
                    ->label(__('staff.users.actions.activate'))
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(function (User $record): bool {
                        $actor = Auth::user();

                        return ! $record->is_active
                               && $actor instanceof User
                               && $actor->can('activate', $record);
                    })
                    ->action(function (User $record, UserService $userService): void {
                        /** @var User $actor */
                        $actor = Auth::user();
                        $userService->activate($actor, $record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
