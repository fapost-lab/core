<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts\Pages;

use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Support\BroadcastMessage;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Filament\Assistant\Resources\Broadcasts\BroadcastResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class EditBroadcast extends EditRecord
{
    protected static string $resource = BroadcastResource::class;

    /**
     * Normalize the locale-tabbed message input and refuse to proceed unless
     * the tenant's base language has non-empty text — SendBroadcastRecipientJob
     * skips recipients whose resolved text is blank, so an unusable draft
     * would silently burn the whole run.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['message'] = BroadcastMessage::clean($data['message'] ?? null);

        $this->guardBaseLanguage($data['message']);

        return $data;
    }

    /**
     * Saves only while the broadcast is still a draft. The page checks that when it opens and on each request, but a
     * run can start between that check and this write, and a started run reads the message for each recipient as it
     * delivers: changing it then would change what the rest of the audience receives. So the row is locked and its
     * status read again inside the write.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $locked = Broadcast::query()->whereKey($record->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Broadcast || BroadcastStatus::Draft !== $locked->status) {
                Notification::make()
                    ->danger()
                    ->title(__('broadcast.errors.not_editable'))
                    ->send();

                $this->halt();
            }

            $locked->update($data);

            return $locked;
        });
    }

    /**
     * @param  array<string, string>|null  $message
     */
    private function guardBaseLanguage(?array $message): void
    {
        $baseLanguage = app(TenantSettings::class)->content_base_language;

        if (BroadcastMessage::hasBaseLanguageText($message, $baseLanguage)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('broadcast.errors.message_required_base_language', ['language' => mb_strtoupper($baseLanguage)]))
            ->send();

        $this->halt();
    }
}
