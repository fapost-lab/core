<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Optional companion of a node handler that needs a contact by type but can name its own recipient in a node's config.
 *
 * A node whose config names its recipient (a `send_message` addressee) reaches that recipient, not the contact of the
 * run, so {@see \App\Domains\Flow\Validation\ContactContextChecker} does not refuse it by its type in a flow whose trigger gives no contact; the
 * rest of its config is still checked for `contact.*` and `module.*` reads and contact variables. Core-only: a
 * Solution's node declares its context through Foundation's `DeclaresContextRequirementInterface`.
 */
interface NamesOwnRecipientInterface
{
    /**
     * Whether this node's config names the recipient the node reaches instead of the contact of the run.
     *
     * Must be pure: the same config always gives the same answer.
     *
     * @param  array<array-key, mixed>  $config  the node's `config`
     */
    public function namesRecipient(array $config): bool;
}
