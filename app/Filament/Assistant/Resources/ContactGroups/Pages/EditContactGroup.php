<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactGroups\Pages;

use App\Filament\Assistant\Resources\ContactGroups\ContactGroupResource;
use Filament\Resources\Pages\EditRecord;

final class EditContactGroup extends EditRecord
{
    protected static string $resource = ContactGroupResource::class;
}
