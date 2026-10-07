<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assistants\Pages;

use App\Filament\Resources\Assistants\AssistantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListAssistants extends ListRecords
{
    protected static string $resource = AssistantResource::class;

    public function getSubheading(): ?string
    {
        return AssistantResource::isLimitReached() ? AssistantResource::limitHint() : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            // The policy cannot hide it from admins (Gate::before), so the limit is checked here too.
            CreateAction::make()->visible(fn (): bool => AssistantResource::canCreate()),
        ];
    }
}
