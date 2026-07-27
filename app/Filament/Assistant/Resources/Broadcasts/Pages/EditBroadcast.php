<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Broadcasts\Pages;

use App\Filament\Assistant\Resources\Broadcasts\BroadcastResource;
use Filament\Resources\Pages\EditRecord;

final class EditBroadcast extends EditRecord
{
    protected static string $resource = BroadcastResource::class;
}
