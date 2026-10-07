<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\CreatePendingUserService;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\ChecksRecordLimitOnMount;
use App\Filament\Support\RecordLimit;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

final class CreateUser extends CreateRecord
{
    use ChecksRecordLimitOnMount;

    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = Auth::user();

        try {
            return app(CreatePendingUserService::class)->create($actor, [
                'name'    => $data['name'],
                'email'   => $data['email'],
                'phone'   => $data['phone'] ?? null,
                'role_id' => $data['role_id'],
            ]);
        } catch (RecordLimitReachedException $e) {
            RecordLimit::notifyReached($e, 'staff.users.limit');

            throw new Halt();
        }
    }
}
