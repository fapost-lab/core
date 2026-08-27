<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\State\Variables\Variable;
use Fapost\Foundation\DTO\NodeExecutionContext;
use InvalidArgumentException;

/**
 * Translates UI-level {@see Variable} (name + storage + optional group) into
 * the canonical state path used by {@code ScopedStateWriter}/{@code ScopedStateReader}.
 *
 * The resolver is symmetric for read and write: the same {@see Variable} maps
 * to the same path used to set or read the value, so handlers can rely on a
 * single contract regardless of side.
 *
 * Path conventions match the existing state writers/readers:
 *  - {@code Contact + name + null group}  → {@code contact.{name}}
 *  - {@code Contact + name + group}       → {@code contact.{group}.{name}}
 *  - {@code Session + name}               → {@code flow.{name}}
 */
interface VariableResolverInterface
{
    /**
     * Resolve the canonical state path for a variable, suitable for both
     * {@code stateChanges} keys (session writes) and direct
     * {@code ContactWriter::write()} calls (contact writes).
     *
     * @throws InvalidArgumentException When {@see Variable} contradicts
     *                                  storage/group invariants.
     */
    public function resolveTargetPath(Variable $variable): string;

    /**
     * Read the current value of {@see $variable} through the engine's
     * unified {@code ScopedStateReader} (delivered via {@see NodeExecutionContext}).
     *
     * Returns {@code null} when the path is not populated. Returns
     * {@code null} as well when no reader is attached — used by legacy
     * unit tests that build the context manually.
     */
    public function read(Variable $variable, NodeExecutionContext $context): mixed;

    /**
     * Backward-compat: parse a legacy {@code save_to} / {@code target+key}
     * string into the new {@see Variable} shape.
     *
     * Supported formats:
     *  - {@code "flow.foo"}        → Session, name=foo, group=null
     *  - {@code "contact.foo"}     → Contact, name=foo, group=null
     *  - {@code "contact.bar.foo"} → Contact, name=foo, group=bar
     *  - {@code "foo"}             → Session, name=foo, group=null  (historical default)
     *
     * @throws InvalidArgumentException For depth >1 contact paths,
     *                                  empty input, or invalid identifiers.
     */
    public function fromLegacyPath(string $path): Variable;
}
