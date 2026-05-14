<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class RoleFormSchema
{
    public static function configure(Schema $schema, ?Role $record = null): Schema
    {
        $sections = [];
        foreach (Permission::groupedByGroup() as $group => $cases) {
            $sections[] = Section::make(__('staff.permission_groups.' . $group))
                ->schema([
                    CheckboxList::make('permission_groups.' . $group)
                        ->hiddenLabel()
                        ->default([])
                        ->options(
                            collect($cases)->mapWithKeys(static fn (Permission $c) => [
                                $c->value => $c->label(),
                            ])->all(),
                        )
                        ->descriptions(self::buildDescriptions($cases))
                        ->columns(2)
                        ->bulkToggleable(),
                ]);
        }

        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('staff.roles.fields.name'))
                    ->required()
                    ->maxLength(255)
                    ->disabled((bool) ($record?->is_system)),
                TextInput::make('display_name')
                    ->label(__('staff.roles.fields.display_name'))
                    ->maxLength(255),
                ...$sections,
            ]);
    }

    /**
     * Build option descriptions for the CheckboxList.
     *
     * Sensitive permissions prepend a warning line. Non-empty catalog
     * descriptions are shown below. Options with neither are omitted.
     *
     * @param  list<Permission>  $cases
     * @return array<string, HtmlString|string>
     */
    private static function buildDescriptions(array $cases): array
    {
        $descriptions = [];

        foreach ($cases as $c) {
            $parts = [];

            if ($c->isSensitive()) {
                $warning = e(__('staff.permissions.sensitive_warning'));
                $parts[] = '<span class="font-medium text-warning-600 dark:text-warning-400">⚠ ' . $warning . '</span>';
            }

            $desc = $c->description();
            if ('' !== $desc) {
                $parts[] = '<span class="text-gray-500 dark:text-gray-400">' . e($desc) . '</span>';
            }

            if ([] !== $parts) {
                $descriptions[$c->value] = new HtmlString(implode('<br>', $parts));
            }
        }

        return $descriptions;
    }
}
