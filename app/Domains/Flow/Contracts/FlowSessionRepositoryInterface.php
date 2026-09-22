<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowSession;

interface FlowSessionRepositoryInterface
{
    /**
     * The session a new inbound message should be routed against: `active`,
     * `waiting_input`, or `paused` (a `delayed(resumeAt: ...)` park — the
     * routing pipeline decides whether that's still busy or due to wake).
     */
    public function findActiveForContact(Contact $contact, string $assistantId): ?FlowSession;

    public function findById(string $sessionId): ?FlowSession;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): FlowSession;

    /** Marks the session as cancelled (used for reset commands). */
    public function cancel(FlowSession $session): void;
}
