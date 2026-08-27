<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowLogs\Pages;

use App\Filament\Assistant\Resources\FlowLogs\FlowLogResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewFlowLog extends ViewRecord
{
    protected static string $resource = FlowLogResource::class;
}
