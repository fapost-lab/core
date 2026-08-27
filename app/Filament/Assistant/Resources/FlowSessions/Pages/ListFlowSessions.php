<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowSessions\Pages;

use App\Filament\Assistant\Resources\FlowSessions\FlowSessionResource;
use Filament\Resources\Pages\ListRecords;

final class ListFlowSessions extends ListRecords
{
    protected static string $resource = FlowSessionResource::class;
}
