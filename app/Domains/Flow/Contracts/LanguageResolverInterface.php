<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowSession;

interface LanguageResolverInterface
{
    public function resolve(Contact $contact, FlowSession $session): string;
}
