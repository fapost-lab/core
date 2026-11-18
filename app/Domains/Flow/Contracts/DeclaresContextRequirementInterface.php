<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Enums\NodeContextRequirement;

/**
 * A node handler that states what it needs from the run: a contact, none, or either.
 *
 * Optional: a handler that does not implement it needs a contact ({@see NodeContextRequirement::Contact}). The
 * declaration covers the handler itself; what a node's config reads or writes (`contact.*`, `module.*`, a contact
 * variable) is found by {@see \App\Domains\Flow\Validation\ContactContextChecker} on top of it.
 */
interface DeclaresContextRequirementInterface
{
    public function contextRequirement(): NodeContextRequirement;
}
