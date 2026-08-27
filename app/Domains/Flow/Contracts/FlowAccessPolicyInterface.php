<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDefinition;

/**
 * Decides whether a contact is allowed to START a given flow.
 *
 * The single rule today: a public flow is always startable; a private
 * (non-public) flow requires the contact to be authenticated
 * ({@code contacts.is_authenticated}). This is the gate counterpart to the
 * {@code auth_request} node, which sets that flag.
 */
interface FlowAccessPolicyInterface
{
    public function canStart(FlowDefinition $definition, Contact $contact): bool;
}
