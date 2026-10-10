<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\RelationManagers;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Filament\Resources\Assistants\Schemas\ChannelFormSchema;
use App\Filament\Support\ChannelWebhookFeedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class ChannelsRelationManager extends RelationManager
{
    protected static string $relationship = 'channels';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedSignal;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('staff.channels.plural_label');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof Assistant) {
            return false;
        }

        if (! Gate::check('view', $ownerRecord)) {
            return false;
        }

        return parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return ChannelFormSchema::configure($schema);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('staff.channels.fields.type'))
                    ->formatStateUsing(function (mixed $state): string {
                        $enum = $state instanceof ChannelTypeEnum ? $state : ChannelTypeEnum::from((string)$state);

                        return __($enum->labelKey());
                    }),
                TextColumn::make('webhook_public_hash')
                    ->label(__('staff.channels.fields.webhook_hash'))
                    ->copyable()
                    ->fontFamily('mono'),
                ChannelWebhookFeedback::statusColumn(),
                IconColumn::make('is_active')
                    ->label(__('staff.channels.fields.is_active'))
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label(__('staff.channels.fields.updated_at'))
                    ->dateTime(),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(
                        function (array $data, HasActions&HasSchemas $livewire, Model $record, ?Table $table): void {
                            if ('' === ($data['token'] ?? '')) {
                                unset($data['token']);
                            }

                            if ('' === ($data['secret_token'] ?? '')) {
                                unset($data['secret_token']);
                            }

                            /** @var Channel $record */
                            ChannelWebhookFeedback::afterWrite(app(ChannelServiceInterface::class)->update($record, $data));
                        }
                    ),
                ChannelWebhookFeedback::reregisterAction(),
                Action::make('rotateWebhookHash')
                    ->label(__('staff.channels.actions.rotate_webhook_hash'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(static fn (Channel $record): bool => Gate::allows('rotateWebhook', $record))
                    ->requiresConfirmation()
                    ->modalHeading(__('staff.channels.actions.rotate_webhook_hash'))
                    ->modalDescription(__('staff.channels.actions.rotate_webhook_hash_description'))
                    ->action(function (Channel $record): void {
                        Gate::authorize('rotateWebhook', $record);

                        $channel = app(ChannelServiceInterface::class)->rotateWebhookHash($record);

                        ChannelWebhookFeedback::afterWrite($channel);

                        Notification::make()
                            ->title(__('staff.channels.notifications.hash_rotated_title'))
                            ->body($channel->webhook_public_hash)
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->after(static function (Channel $record): void {
                        ChannelWebhookFeedback::afterDeregister($record);
                    }),
            ]);
    }
}
