<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Flows\Schemas;

use App\Domains\Flow\Models\FlowGroup;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

final class FlowFormSchema
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('assistant.flows.fields.name'))
                ->required()
                ->maxLength(255),

            Select::make('flow_group_id')
                ->label(__('assistant.flows.fields.group'))
                ->relationship(
                    name: 'group',
                    titleAttribute: 'name',
                    modifyQueryUsing: fn (Builder $query) => $query->where(
                        'assistant_id',
                        Filament::getTenant()?->getKey()
                    )
                )
                ->searchable()
                ->preload()
                ->createOptionForm([
                    TextInput::make('name')
                        ->label(__('assistant.flows.fields.group_name'))
                        ->required()
                        ->maxLength(255),
                ])
                ->createOptionUsing(fn (array $data): string => FlowGroup::create([
                    'tenant_id'    => app(TenantContextInterface::class)->get()->id,
                    'assistant_id' => (string)Filament::getTenant()->getKey(),
                    'name'         => $data['name'],
                ])->getKey())
                ->createOptionModalHeading(__('assistant.flows.groups.create_modal_heading')),

            Textarea::make('description')
                ->label(__('assistant.flows.fields.description'))
                ->rows(2),

            Toggle::make('is_public')
                ->label(__('assistant.flows.fields.is_public'))
                ->helperText(__('assistant.flows.fields.is_public_hint'))
                ->default(true),
        ]);
    }
}
