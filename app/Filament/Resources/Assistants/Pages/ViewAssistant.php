<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Pages;

use App\Filament\Resources\Assistants\AssistantResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewAssistant extends ViewRecord
{
    protected static string $resource = AssistantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->url(fn($record): string => EditAssistant::getUrl(['record' => $record])),
        ];
    }
}
