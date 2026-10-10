<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Conversation\Enums\ConversationStatus;
use App\Domains\Conversation\Services\ConversationInbox;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Closing or reopening a thread: the status is sent, not toggled, so a repeated request changes nothing. Needs `reply`
 * on the thread, as the Filament page did.
 */
final class UpdateConversationStatusRequest extends FormRequest
{
    public function authorize(ConversationInbox $inbox, CurrentAssistantInterface $assistant): bool
    {
        return $this->user()?->can('reply', $inbox->find($assistant->get(), (string) $this->route('record'))) ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in([ConversationStatus::Open->value, ConversationStatus::Closed->value])],
        ];
    }

    public function status(): ConversationStatus
    {
        return ConversationStatus::from((string) $this->validated('status'));
    }
}
