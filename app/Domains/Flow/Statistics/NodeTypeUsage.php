<?php

declare(strict_types=1);

namespace App\Domains\Flow\Statistics;

use Carbon\CarbonImmutable;

/**
 * Usage of a single `type@version` node pair within one tenant.
 *
 * The pair, not the bare type, is the unit: a handler is retired per version
 * ({@see \App\Domains\Flow\Registry\NodeHandlerRegistry} resolves by
 * `type@version`), so "is `send_message` still used" is the wrong question when
 * only v1 is being dropped.
 */
final readonly class NodeTypeUsage
{
    public function __construct(
        public string $nodeType,
        public int $nodeVersion,
        /** Active flows whose definition contains at least one such node. */
        public int $activeFlows,
        /** Node occurrences across those definitions. */
        public int $activeNodes,
        /** Executions logged inside the runtime window. */
        public int $executions,
        /** Executions that ended on `failed` inside the runtime window. */
        public int $failures,
        public ?CarbonImmutable $lastExecutedAt,
        /** Whether a handler is registered for exactly this `type@version`. */
        public bool $handlerRegistered,
    ) {
    }

    /**
     * A node present in an active definition with no handler registered for its
     * version: the flow is already broken and fails the moment it reaches it.
     */
    public function isOrphaned(): bool
    {
        return ! $this->handlerRegistered && $this->activeNodes > 0;
    }

    public function isUsedInActiveFlows(): bool
    {
        return $this->activeNodes > 0;
    }

    public function key(): string
    {
        return "{$this->nodeType}@{$this->nodeVersion}";
    }
}
