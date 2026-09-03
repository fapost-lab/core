<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Channels\Tables;

use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class ChannelsTable
{
    public static function configureColumns(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('staff.channels.fields.type'))
                    ->formatStateUsing(function (mixed $state): string {
                        $enum = $state instanceof ChannelTypeEnum ? $state : ChannelTypeEnum::from((string)$state);

                        return __($enum->labelKey());
                    }),
                // The bot handle is what staff actually need at a glance — it
                // opens the live bot. The webhook hash moved to the edit form:
                // it is a debugging detail, not a daily one.
                TextColumn::make('bot_link')
                    ->label(__('staff.channels.fields.bot_link'))
                    ->state(static fn (Channel $record): ?string => $record->publicHandle())
                    ->url(static fn (Channel $record): ?string => $record->publicUrl(), shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->iconPosition(IconPosition::After)
                    ->color('primary')
                    // No copyable() here: it binds `x-on:click.prevent.stop`,
                    // which swallows the click before the link can open.
                    ->placeholder(__('staff.channels.fields.bot_link_pending')),
                IconColumn::make('is_active')
                    ->label(__('staff.channels.fields.is_active'))
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label(__('staff.channels.fields.updated_at'))
                    ->dateTime(),
            ]);
    }

    public static function configureRecordActions(Table $table, ChannelServiceInterface $channelService): Table
    {
        return $table
            ->recordActions([
                EditAction::make()
                    ->using(
                        function (array $data, HasActions&HasSchemas $livewire, Model $record, ?Table $table) use (
                            $channelService
                        ): void {
                            if ('' === ($data['token'] ?? '')) {
                                unset($data['token']);
                            }

                            if ('' === ($data['secret_token'] ?? '')) {
                                unset($data['secret_token']);
                            }

                            /** @var Channel $record */
                            $channelService->update($record, $data);
                        }
                    ),
                Action::make('rotateWebhookHash')
                    ->label(__('staff.channels.actions.rotate_webhook_hash'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalHeading(__('staff.channels.actions.rotate_webhook_hash'))
                    ->modalDescription(__('staff.channels.actions.rotate_webhook_hash_description'))
                    ->action(function (Channel $record) use ($channelService): void {
                        Gate::authorize('rotateWebhook', $record);

                        $channel = $channelService->rotateWebhookHash($record);

                        Notification::make()
                            ->title(__('staff.channels.notifications.hash_rotated_title'))
                            ->body($channel->webhook_public_hash)
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
            ]);
    }
}
