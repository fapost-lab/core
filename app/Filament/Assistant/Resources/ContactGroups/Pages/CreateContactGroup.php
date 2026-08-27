<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactGroups\Pages;

use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Assistant\Resources\ContactGroups\ContactGroupResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateContactGroup extends CreateRecord
{
    protected static string $resource = ContactGroupResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ContactGroup::create([
            'tenant_id'   => app(TenantContextInterface::class)->get()->getId(),
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
    }
}
