<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Conversation\Services\ConversationInbox;
use App\Domains\Staff\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An administrator's seizure of a thread another operator holds: the holder the administrator saw, so a thread that
 * changed hands meanwhile is left alone. Needs `reply` on the thread and the administrator role.
 */
final class SeizeConversationRequest extends FormRequest
{
    public function authorize(ConversationInbox $inbox, CurrentAssistantInterface $assistant): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->isAdmin()
            && $user->can('reply', $inbox->find($assistant->get(), (string) $this->route('record')));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'expected_owner' => ['required', 'string', 'uuid'],
        ];
    }

    public function expectedOwner(): string
    {
        return (string) $this->validated('expected_owner');
    }
}
