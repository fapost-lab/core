<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\RoleFormDataMapper;
use App\Domains\Staff\Services\RoleWriterService;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleFormSchema;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    public function form(Schema $schema): Schema
    {
        return RoleFormSchema::configure($schema, $this->getRecord());
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['permission_groups'] = app(RoleFormDataMapper::class)->permissionGroupsFromRole($this->getRecord());

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = Auth::user();

        return app(RoleWriterService::class)->update($actor, $record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! $this->getRecord()->is_system),
        ];
    }
}
