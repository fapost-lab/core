<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowSession;

final class FlowSessionRepository implements FlowSessionRepositoryInterface
{
    public function findActiveForContact(Contact $contact, string $assistantId): ?FlowSession
    {
        return FlowSession::query()
            ->where('tenant_id', $contact->tenant_id)
            ->where('assistant_id', $assistantId)
            ->where('contact_id', $contact->getKey())
            ->whereIn('status', [
                FlowSessionStatus::Active,
                FlowSessionStatus::WaitingInput,
            ])
            ->latest('updated_at')
            ->first();
    }

    public function create(array $attributes): FlowSession
    {
        return FlowSession::query()->create($attributes);
    }

    public function cancel(FlowSession $session): void
    {
        $session->status = FlowSessionStatus::Cancelled;
        $session->save();
    }
}
