<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Models\User;
use App\Domains\Staff\Models\UserActivationToken;
use Illuminate\Support\Str;

/**
 * Issues a single activation token per user (replaces any previous row).
 */
final class ActivationTokenService
{
    private const int TTL_HOURS = 72;

    /**
     * @return non-empty-string Plain token for URLs (store only hash in DB).
     */
    public function issue(User $user): string
    {
        $plain = Str::random(64);

        UserActivationToken::query()->where('user_id', $user->getKey())->delete();

        UserActivationToken::query()->create([
            'user_id'    => $user->getKey(),
            'token'      => hash('sha256', $plain),
            'expires_at' => now()->addHours(self::TTL_HOURS),
            'created_at' => now(),
        ]);

        return $plain;
    }

    public function findValidUserByPlainToken(string $plainToken): ?User
    {
        $hash = hash('sha256', $plainToken);

        $row = UserActivationToken::query()
            ->where('token', $hash)
            ->where('expires_at', '>', now())
            ->first();

        if (null === $row) {
            return null;
        }

        return User::query()->find($row->user_id);
    }

    public function deleteForUser(User $user): void
    {
        UserActivationToken::query()->where('user_id', $user->getKey())->delete();
    }
}
