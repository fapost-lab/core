<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Pages;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Filament\Resources\Assistants\AssistantResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

final class EditAssistant extends EditRecord
{
    protected static string $resource = AssistantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Assistant $record */
        return app(AssistantServiceInterface::class)->update($record, $data);
    }
}
