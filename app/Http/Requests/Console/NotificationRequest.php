<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Staff\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A read or a write of the signed-in user's own notifications from the shell's bell. Every staff user who may use the
 * console has an inbox; which notifications are theirs is decided by
 * {@see \App\Domains\Staff\Services\StaffNotificationInbox}, never by a policy an admin would pass.
 */
final class NotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->canAccessConsole();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }

    public function staff(): User
    {
        $user = $this->user();

        // authorize() ran first and let only a staff user through.
        assert($user instanceof User);

        return $user;
    }
}
