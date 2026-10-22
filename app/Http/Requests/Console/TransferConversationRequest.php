<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Conversation\Services\ConversationInbox;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Handing a thread to another operator: the id of the receiver. Needs `reply` on the thread; whether the user holds it
 * and whether the receiver can take it is decided by {@see ConversationInbox::transfer()}.
 */
final class TransferConversationRequest extends FormRequest
{
    public function authorize(ConversationInbox $inbox, CurrentAssistantInterface $assistant): bool
    {
        return $this->user()?->can('reply', $inbox->find($assistant->get(), (string) $this->route('record'))) ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'uuid'],
        ];
    }

    public function targetId(): string
    {
        return (string) $this->validated('user_id');
    }
}
