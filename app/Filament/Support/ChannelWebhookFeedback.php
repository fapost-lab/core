<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelWebhookSyncOutcome;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Gate;

/**
 * What the Filament channel screens say about the provider webhook. A write never fails on the provider's refusal
 * (the channel stores the outcome), so the screens show it afterwards instead of an error page.
 */
final class ChannelWebhookFeedback
{
    /**
     * After a create, an update or a rotation: warns when the provider refused the registration, and when it did not
     * confirm taking the webhook down on a deactivation.
     */
    public static function afterWrite(Channel $channel): void
    {
        if ($channel->webhookRegistrationFailed()) {
            self::warn('webhook_failed');
        }

        self::afterDeregister($channel);
    }

    /**
     * After a deactivation or a delete: warns when the provider did not confirm taking the webhook down.
     */
    public static function afterDeregister(Channel $channel): void
    {
        if (app(ChannelWebhookSyncOutcome::class)->deregisterFailed((string)$channel->getKey())) {
            self::warn('webhook_deregister_failed');
        }
    }

    /**
     * Badge of the table column: shown only while the last registration is refused.
     */
    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('webhook_status')
            ->label(__('staff.channels.fields.webhook_status'))
            ->badge()
            ->color('danger')
            ->state(static fn (Channel $record): ?string => $record->webhookRegistrationFailed()
                ? __('staff.channels.fields.webhook_failed')
                : null);
    }

    /**
     * Registers the webhook again, with the channel as it is. Visible only while the last registration is refused.
     */
    public static function reregisterAction(): Action
    {
        return Action::make('reregisterWebhook')
            ->label(__('staff.channels.actions.reregister_webhook'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(static fn (Channel $record): bool => $record->webhookRegistrationFailed()
                && Gate::allows('update', $record))
            ->action(static function (Channel $record): void {
                Gate::authorize('update', $record);

                $channel = app(ChannelServiceInterface::class)->reregisterWebhook($record);

                if ($channel->webhookRegistrationFailed()) {
                    self::warn('webhook_failed');

                    return;
                }

                Notification::make()
                    ->title(__('staff.channels.notifications.webhook_registered_title'))
                    ->success()
                    ->send();
            });
    }

    private static function warn(string $key): void
    {
        Notification::make()
            ->title(__("staff.channels.notifications.{$key}_title"))
            ->body(__("staff.channels.notifications.{$key}_body"))
            ->warning()
            ->persistent()
            ->send();
    }
}
