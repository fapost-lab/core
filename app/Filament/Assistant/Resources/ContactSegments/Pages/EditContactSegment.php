<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments\Pages;

use App\Domains\Contact\Enums\SegmentMatch;
use App\Filament\Assistant\Resources\ContactSegments\ContactSegmentResource;
use Filament\Resources\Pages\EditRecord;

final class EditContactSegment extends EditRecord
{
    protected static string $resource = ContactSegmentResource::class;

    /**
     * Split the stored `rules` json into the form's match / conditions fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];

        $data['match']      = $rules['match'] ?? SegmentMatch::All->value;
        $data['conditions'] = is_array($rules['conditions'] ?? null) ? $rules['conditions'] : [];

        return $data;
    }

    /**
     * Fold the match / conditions fields back into the `rules` json column.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['rules'] = [
            'match'      => $data['match'] ?? SegmentMatch::All->value,
            'conditions' => is_array($data['conditions'] ?? null) ? array_values($data['conditions']) : [],
        ];

        unset($data['match'], $data['conditions']);

        return $data;
    }
}
