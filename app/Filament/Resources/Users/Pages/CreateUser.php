<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\CreatePendingUserService;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = Auth::user();

        return app(CreatePendingUserService::class)->create($actor, [
            'name'    => $data['name'],
            'email'   => $data['email'],
            'phone'   => $data['phone'] ?? null,
            'role_id' => $data['role_id'],
        ]);
    }
}
