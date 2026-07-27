<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments\Pages;

use App\Domains\Contact\Enums\SegmentMatch;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Filament\Assistant\Resources\ContactSegments\ContactSegmentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

final class CreateContactSegment extends CreateRecord
{
    protected static string $resource = ContactSegmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return ContactSegment::create([
            'tenant_id' => app(TenantContextInterface::class)->get()->getId(),
            'name'      => $data['name'],
            'rules'     => [
                'match'      => $data['match'] ?? SegmentMatch::All->value,
                'conditions' => is_array($data['conditions'] ?? null) ? array_values($data['conditions']) : [],
            ],
        ]);
    }
}
