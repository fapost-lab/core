<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FlowAccessPolicyInterface;
use App\Domains\Flow\Models\FlowDefinition;

/**
 * Default flow access policy: public flows are open; private flows require an
 * authenticated contact. See {@see FlowAccessPolicyInterface}.
 */
final class FlowAccessPolicy implements FlowAccessPolicyInterface
{
    public function canStart(FlowDefinition $definition, Contact $contact): bool
    {
        // Fail open: only an explicit `false` marks a flow private. A null/absent
        // value (legacy rows predating the column, in-memory models) is treated
        // as public, matching the column's DB default.
        if (false !== $definition->is_public) {
            return true;
        }

        return (bool) $contact->is_authenticated;
    }
}
