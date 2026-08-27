<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domains\Staff\Models\User;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $actor */
        $actor = Auth::user();

        if (array_key_exists('roles', $data)) {
            Gate::forUser($actor)->authorize('updateRoles', [$record, $data['roles']]);
        }

        $record = parent::handleRecordUpdate($record, $data);
        $this->form->model($record)->saveRelationships();

        return $record;
    }
}
