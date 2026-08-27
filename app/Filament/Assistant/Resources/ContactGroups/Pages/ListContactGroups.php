<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactGroups\Pages;

use App\Filament\Assistant\Resources\ContactGroups\ContactGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListContactGroups extends ListRecords
{
    protected static string $resource = ContactGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
