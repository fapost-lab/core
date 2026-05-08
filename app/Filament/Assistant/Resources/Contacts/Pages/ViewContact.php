<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Contacts\Pages;

use App\Filament\Assistant\Resources\Contacts\ContactResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;
}
