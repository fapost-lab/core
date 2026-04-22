<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowGroups\Pages;

use App\Filament\Assistant\Resources\FlowGroups\FlowGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListFlowGroups extends ListRecords
{
    protected static string $resource = FlowGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
