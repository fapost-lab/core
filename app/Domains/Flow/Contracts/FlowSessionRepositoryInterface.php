<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowSession;

interface FlowSessionRepositoryInterface
{
    public function findActiveForContact(Contact $contact, string $assistantId): ?FlowSession;
}
