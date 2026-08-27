<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments\Pages;

use App\Filament\Assistant\Resources\ContactSegments\ContactSegmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListContactSegments extends ListRecords
{
    protected static string $resource = ContactSegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
