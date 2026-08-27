<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\ContactSegments\Pages;

use App\Domains\Contact\Enums\SegmentConditionType;
use App\Domains\Contact\Enums\SegmentMatch;
use App\Filament\Assistant\Resources\ContactSegments\ContactSegmentResource;
use Filament\Resources\Pages\EditRecord;

final class EditContactSegment extends EditRecord
{
    protected static string $resource = ContactSegmentResource::class;

    /**
     * Split the stored `rules` json into the form's match / conditions fields.
     * `group` conditions also get their `value` copied into `value_group` so
     * the dedicated select re-selects the stored group ids.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $rules      = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        $conditions = is_array($rules['conditions'] ?? null) ? $rules['conditions'] : [];

        $data['match']      = $rules['match'] ?? SegmentMatch::All->value;
        $data['conditions'] = array_map([self::class, 'unfoldConditionValue'], $conditions);

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
            'conditions' => array_map(
                [self::class, 'foldConditionValue'],
                is_array($data['conditions'] ?? null) ? array_values($data['conditions']) : [],
            ),
        ];

        unset($data['match'], $data['conditions']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private static function unfoldConditionValue(array $condition): array
    {
        if (SegmentConditionType::Group->value === ($condition['type'] ?? null) && is_array($condition['value'] ?? null)) {
            $condition['value_group'] = $condition['value'];
        }

        return $condition;
    }

    /**
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
