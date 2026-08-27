<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments\Pages;

use App\Domains\Contact\Enums\SegmentConditionType;
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
                'conditions' => array_map(
                    [self::class, 'foldConditionValue'],
                    is_array($data['conditions'] ?? null) ? array_values($data['conditions']) : [],
                ),
            ],
        ]);
    }

    /**
     * The `group` condition type stores its value via a dedicated
     * `value_group` select (see {@see \App\Filament\Assistant\Resources\ContactSegments\Schemas\ContactSegmentFormSchema}),
     * so it is folded into the single `value` key the resolver expects before
     * persisting.
     *
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private static function foldConditionValue(array $condition): array
    {
        if (SegmentConditionType::Group->value === ($condition['type'] ?? null)) {
            $condition['value'] = is_array($condition['value_group'] ?? null)
                ? array_values($condition['value_group'])
                : [];
        }

        unset($condition['value_group']);

        return $condition;
    }
}
