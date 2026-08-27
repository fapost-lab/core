<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts\Pages;

use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Filament\Assistant\Resources\Broadcasts\BroadcastResource;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class CreateBroadcast extends CreateRecord
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
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['message'] = $this->cleanLocalizedMessage($data['message'] ?? null);

        $this->guardBaseLanguage($data['message']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['tenant_id']    = app(TenantContextInterface::class)->get()->getId();
        $data['assistant_id'] = (string) Filament::getTenant()->getKey();
        $data['created_by']   = null !== Auth::id() ? (string) Auth::id() : null;
        $data['status']       = BroadcastStatus::Draft->value;

        return Broadcast::create($data);
    }

    /**
     * Drop blank locale entries so the JSON column stays canonical — mirrors
     * AssistantSettings::cleanLocalized(), minus the flat-string legacy branch
     * that field never had.
     *
     * @return array<string, string>|null
     */
    private function cleanLocalizedMessage(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $clean = [];
        foreach ($value as $lang => $text) {
            if (is_string($lang) && is_string($text) && '' !== mb_trim($text)) {
                $clean[$lang] = $text;
            }
        }

        return [] === $clean ? null : $clean;
    }

    /**
     * @param  array<string, string>|null  $message
     */
    private function guardBaseLanguage(?array $message): void
    {
        $baseLanguage = app(TenantSettings::class)->content_base_language;
        $text         = $message[$baseLanguage] ?? null;

        if (is_string($text) && '' !== mb_trim($text)) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('broadcast.errors.message_required_base_language', ['language' => mb_strtoupper($baseLanguage)]))
            ->send();

        $this->halt();
    }
}
