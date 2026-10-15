<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ActivateUserService
{
    public function __construct(
        private readonly ActivationTokenService $activationTokenService,
    ) {
    }

    /**
     * Sets the invitee's password and makes the account active. Signing the invitee in is the caller's: it belongs to
     * the request's session, which the activation controller replaces.
     *
     * @param  array{password: string, password_confirmation: string}  $credentials
     */
    public function activate(string $plainToken, array $credentials): User
    {
        $user = $this->activationTokenService->findValidUserByPlainToken($plainToken);

        if (null === $user || UserStatus::Pending !== $user->status) {
            throw ValidationException::withMessages([
                'token' => __('auth.activation.invalid'),
            ]);
        }

        return DB::transaction(function () use ($user, $credentials): User {
            $user->fill([
                'password' => $credentials['password'],
                'status'   => UserStatus::Active,
            ]);
            $user->save();

            $this->activationTokenService->deleteForUser($user);

            return $user;
        });
    }
}
