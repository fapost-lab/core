<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Finds or creates the tenant's platform support user.
 *
 * One per tenant, created on the first support entry. It is an administrator with no password, so it
 * cannot sign in through the login form, and its email is on a domain that cannot receive mail.
 */
final class PlatformSupportUserService
{
    public const string NAME = 'Platform support';

    /** `.invalid` is reserved (RFC 2606): the address can never be delivered to. */
    public const string EMAIL = 'support@platform.invalid';

    /**
     * Addresses no tenant user may have: the `.invalid` top-level domain, where the support address lives.
     */
    public static function isReservedEmail(string $email): bool
    {
        return str_ends_with(mb_strtolower(mb_trim($email)), '.invalid');
    }

    public function ensure(): User
    {
        $existing = $this->find();

        if ($existing instanceof User) {
            return $this->restoreIfDisabled($existing);
        }

        try {
            return $this->create();
        } catch (UniqueConstraintViolationException $exception) {
            // Two first entries at once: the other one created it.
            return $this->find() ?? throw new RuntimeException(
                'The platform support user cannot be created: its address is taken by another account.',
                0,
                $exception,
            );
        }
    }

    private function find(): ?User
    {
        return User::query()->where('is_platform_support', true)->first();
    }

    private function restoreIfDisabled(User $user): User
    {
        if (UserStatus::Active !== $user->status || ! $user->is_active) {
            $user->forceFill(['status' => UserStatus::Active, 'is_active' => true])->save();
        }

        if (! $user->isAdmin()) {
            $user->assignRole(RoleEnum::Admin->value);
        }

        return $user;
    }

    private function create(): User
    {
        $user = new User();
        $user->forceFill([
            'name'                => self::NAME,
            'email'               => self::EMAIL,
            'email_verified_at'   => now(),
            'password'            => null,
            'status'              => UserStatus::Active,
            'is_active'           => true,
            'is_platform_support' => true,
        ])->save();

        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
