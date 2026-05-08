<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Variables;

/**
 * Where a flow-level variable is persisted.
 *
 * - {@see VariableStorage::Contact}: written into {@code contacts.attributes}
 *   (or a canonical contact column) — survives across sessions.
 * - {@see VariableStorage::Session}: written into the running session's
 *   {@code flow.*} namespace — discarded when the session ends.
 */
enum VariableStorage: string
{
    case Contact = 'contact';
    case Session = 'session';
}
