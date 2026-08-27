<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Flows\Pages;

use App\Filament\Assistant\Resources\Flows\FlowResource;
use Filament\Resources\Pages\EditRecord;

final class EditFlow extends EditRecord
{
    protected static string $resource = FlowResource::class;
}
