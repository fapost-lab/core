<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowLogs\Pages;

use App\Filament\Assistant\Resources\FlowLogs\FlowLogResource;
use Filament\Resources\Pages\ListRecords;

final class ListFlowLogs extends ListRecords
{
    protected static string $resource = FlowLogResource::class;
}
