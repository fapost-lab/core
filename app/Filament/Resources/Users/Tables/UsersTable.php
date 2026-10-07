<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\ResendActivationService;
use App\Domains\Staff\Services\UserService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\RecordLimit;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
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
        // Read on first use and shared by every row of this render, not kept between requests.
        $limit        = null;
        $limitReached = static function () use (&$limit): bool {
            $limit ??= UserResource::limit();

            return $limit->reached;
        };

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('staff.users.table.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('is_platform_support')
                    ->label('')
                    ->badge()
                    ->color('warning')
                    ->state(fn (User $record): ?string => $record->isPlatformSupport() ? __('staff.users.platform_support') : null),
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
                    ->visible(function (User $record) use ($limitReached): bool {
                        $actor = Auth::user();

                        // A deactivated account takes a staff place again when reactivated.
                        return ! $record->is_active
                               && ! $limitReached()
                               && $actor instanceof User
                               && $actor->can('activate', $record);
                    })
                    ->action(function (User $record, UserService $userService): void {
                        /** @var User $actor */
                        $actor = Auth::user();

                        try {
                            $userService->activate($actor, $record);
                        } catch (RecordLimitReachedException $e) {
                            RecordLimit::notifyReached($e, 'staff.users.limit');
                        }
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Without per-record authorization Filament deletes the whole selection with one
                    // query, bypassing both the policy and model events. Authorizing each record is
                    // what makes the Gate refuse the platform support user here.
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
