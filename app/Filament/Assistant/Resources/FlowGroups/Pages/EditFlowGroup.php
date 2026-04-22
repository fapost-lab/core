<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowGroups\Pages;

use App\Filament\Assistant\Resources\FlowGroups\FlowGroupResource;
use Filament\Resources\Pages\EditRecord;

final class EditFlowGroup extends EditRecord
{
    protected static string $resource = FlowGroupResource::class;
}
