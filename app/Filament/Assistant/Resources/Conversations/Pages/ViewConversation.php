<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Conversations\Pages;

use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Models\ConversationMessage;
use App\Filament\Assistant\Resources\Conversations\ConversationResource;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Chat-style transcript for a single thread. Renders messages as bubbles —
 * inbound (contact) left, outbound (bot/staff) right — in chronological order.
 *
 * For the Postgres v1 driver this reads the {@see ConversationMessage} Eloquent
 * surface directly (documented trade-off, spec §10). When a column-store driver
 * lands, this page moves onto the reader port.
 */
final class ViewConversation extends Page
{
    public ?string $record = null;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'filament.assistant.resources.conversations.view-conversation';

    public function mount(string $record): void
    {
        $this->record = $record;
    }

    public function getTitle(): string
    {
        return $this->contactLabel($this->resolveRecord());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $conversation = $this->resolveRecord();

        return [
            'conversation' => $conversation,
            'contactLabel' => $this->contactLabel($conversation),
            'messages'     => $this->messages((string) $conversation->getKey()),
        ];
    }

    /**
     * @return Collection<int, ConversationMessage>
     */
    private function messages(string $conversationId): Collection
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->get();
    }

    private function resolveRecord(): Conversation
    {
        // Route through the resource query so the assistant scope applies —
        // a thread of another assistant must 404, not render.
        /** @var Conversation $conversation */
        $conversation = ConversationResource::getEloquentQuery()
            ->with('contact')
            ->findOrFail($this->record);

        return $conversation;
    }

    private function contactLabel(Conversation $conversation): string
    {
        $contact = $conversation->contact;
        $meta    = is_array($contact?->meta) ? $contact->meta : [];

        $full = mb_trim((string) ($meta['first_name'] ?? '') . ' ' . (string) ($meta['last_name'] ?? ''));

        if ('' !== $full) {
            return $full;
        }

        if ('' !== (string) ($meta['username'] ?? '')) {
            return '@' . $meta['username'];
        }

        return null !== $contact ? (string) $contact->external_id : (string) $conversation->getKey();
    }
}
