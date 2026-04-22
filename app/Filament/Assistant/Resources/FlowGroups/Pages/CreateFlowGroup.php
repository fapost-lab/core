<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\FlowGroups\Pages;

use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Assistant\Resources\FlowGroups\FlowGroupResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateFlowGroup extends CreateRecord
{
    protected static string $resource = FlowGroupResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return FlowGroup::create([
            'tenant_id'    => app(TenantContextInterface::class)->get()->id,
            'assistant_id' => (string) Filament::getTenant()->getKey(),
            'name'         => $data['name'],
        ]);
    }
}
