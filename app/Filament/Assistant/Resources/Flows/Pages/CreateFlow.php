<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Flows\Pages;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Flow\Actions\CreateFlowAction;
use App\Filament\Assistant\Resources\Flows\FlowResource;
use App\Filament\Support\ChecksRecordLimitOnMount;
use App\Filament\Support\RecordLimit;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

final class CreateFlow extends CreateRecord
{
    use ChecksRecordLimitOnMount;

    protected static string $resource = FlowResource::class;

    protected CurrentAssistantInterface $currentAssistant;

    public function boot(CurrentAssistantInterface $currentAssistant): void
    {
        $this->currentAssistant = $currentAssistant;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['assistant_id'] = (string)$this->currentAssistant->get()->getKey();

        try {
            return app(CreateFlowAction::class)->execute($data);
        } catch (RecordLimitReachedException $e) {
            RecordLimit::notifyReached($e, 'assistant.flows.limit');

            throw new Halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return url("/builder/flows/{$this->record->flow_id}");
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }
}
