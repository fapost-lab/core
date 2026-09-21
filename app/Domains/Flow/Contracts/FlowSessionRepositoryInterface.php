<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowSession;

interface FlowSessionRepositoryInterface
{
    public function findActiveForContact(Contact $contact, string $assistantId): ?FlowSession;

    public function findById(string $sessionId): ?FlowSession;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): FlowSession;

    /** Marks the session as cancelled (used for reset commands). */
    public function cancel(FlowSession $session): void;
}
