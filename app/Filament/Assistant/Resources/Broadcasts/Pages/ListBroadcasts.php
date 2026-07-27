<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts\Pages;

use App\Filament\Assistant\Resources\Broadcasts\BroadcastResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListBroadcasts extends ListRecords
{
    protected static string $resource = BroadcastResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
