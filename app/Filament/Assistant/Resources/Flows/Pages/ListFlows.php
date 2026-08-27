<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Flows\Pages;

use App\Filament\Assistant\Resources\Flows\FlowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListFlows extends ListRecords
{
    protected static string $resource = FlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
