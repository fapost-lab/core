<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

use App\Domains\Flow\Exceptions\StateNamespaceViolationException;
use FAPost\Foundation\Flow\Enums\StateNamespace;

/**
 * Runtime authority over which node types may write into which top-level
 * namespaces of {@code flow_sessions.state} (CLAUDE.md § State namespaces).
 *
 * The `system.*` and `rag.*` namespaces are guarded by explicit per-type
 * whitelists; `flow.*` and `call.*` accept writes from any handler (they hold
 * user-configured variable targets). `module.*` is read-only by contract
 * (DataAccessorInterface) and `contact.*` is a derived projection persisted
 * through ContactWriter — neither may ever appear in {@code stateChanges}.
 */
final class SystemStateNamespacePolicy
{
    /**
     * Node types allowed to write `system.*` keys (engine-owned markers:
     * sent-message ids, delay schedules, notify dispatch markers, input retry
     * counters, set_tag idempotency markers).
     */
    private const array SYSTEM_WRITERS = [
        'send_message',
        'input',
        'delay',
        'notify',
        'set_tag',
    ];

    /**
     * Node types allowed to write `rag.*` keys.
     */
    private const array RAG_WRITERS = [
        'rag_query',
    ];

    /**
     * Namespaces open to any handler: user-configurable variable storage.
     */
    private const array OPEN_NAMESPACES = [
        StateNamespace::Flow->value,
        StateNamespace::Call->value,
    ];

    /**
     * Assert that {@code $nodeType} may write the namespaced state key.
     *
     * @throws StateNamespaceViolationException
     */
    public function assertWriteAllowed(string $nodeType, string $key): void
    {
        [$namespace] = explode('.', $key, 2);

        if (in_array($namespace, self::OPEN_NAMESPACES, true)) {
            return;
        }

        $allowed = match ($namespace) {
            StateNamespace::System->value => in_array($nodeType, self::SYSTEM_WRITERS, true),
            StateNamespace::Rag->value    => in_array($nodeType, self::RAG_WRITERS, true),
            default                       => false,
        };

        if (! $allowed) {
            throw StateNamespaceViolationException::forWrite($nodeType, $key);
        }
    }
}
