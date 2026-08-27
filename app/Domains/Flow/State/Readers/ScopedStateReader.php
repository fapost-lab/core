<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Readers;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use Fapost\Foundation\Flow\Contracts\ScopedStateReaderInterface;

/**
 * Unified read surface across all state namespaces for a single node execution.
 *
 * Built per execution by the engine; carries the immutable session state JSON,
 * the resolved Contact model, and the data accessor registry. Returns null
 * for unknown paths, never throws on missing.
 *
 * Routing:
 *  - contact.<key>            → Contact column (id/tenant_id/external_id/platform/language)
 *                               or attributes[<key>] JSONB leaf
 *  - contact.<group>.<key>    → attributes[<group>][<key>] JSONB nested
 *  - module.<name>.<...>      → DataAccessor.get(...) for module
 *  - flow|system|rag|call.*   → flow_sessions.state.<namespace>.<...>
 */
final readonly class ScopedStateReader implements ScopedStateReaderInterface
{
    /**
     * @param  array<string, mixed>  $sessionState  in-memory snapshot of flow_sessions.state for this execution
     */
    public function __construct(
        private array $sessionState,
        private Contact $contact,
        private DataAccessorRegistryInterface $accessors,
    ) {
    }

    public function read(string $path): mixed
    {
        if ('' === $path) {
            return null;
        }

        $segments  = explode('.', $path, 2);
        $namespace = $segments[0];
        $rest      = $segments[1] ?? '';

        return match ($namespace) {
            'contact'                       => $this->readContact($rest),
            'module'                        => $this->readModule($rest),
            'flow', 'system', 'rag', 'call' => $this->readSession($namespace, $rest),
            default                         => null,
        };
    }

    private function readContact(string $rest): mixed
    {
        if ('' === $rest) {
            return null;
        }

        $segments = explode('.', $rest, 2);
        $head     = $segments[0];
        $tail     = $segments[1] ?? null;

        // Canonical column lookup
        if (
            in_array($head, ['id', 'tenant_id', 'external_id', 'platform', 'language', 'is_authenticated'], true)
            && null === $tail
        ) {
            return $this->contact->getAttribute($head);
        }

        $attributes = is_array($this->contact->attributes) ? $this->contact->attributes : [];

        if (null === $tail) {
            return $attributes[$head] ?? null;
        }

        $group = $attributes[$head] ?? null;

        if (! is_array($group)) {
            return null;
        }

        return data_get($group, $tail);
    }

    private function readModule(string $rest): mixed
    {
        if ('' === $rest) {
            return null;
        }

        $segments = explode('.', $rest, 2);
        $module   = $segments[0];
        $key      = $segments[1] ?? null;

        if (null === $key || ! $this->accessors->has($module)) {
            return null;
        }

        return $this->accessors->resolve($module)->get(
            key: $key,
            contactId: (string) $this->contact->getKey(),
            tenantId: (string) $this->contact->tenant_id,
        );
    }

    private function readSession(string $namespace, string $rest): mixed
    {
        $payload = $this->sessionState[$namespace] ?? null;

        if (! is_array($payload)) {
            return null;
        }

        if ('' === $rest) {
            return $payload;
        }

        return data_get($payload, $rest);
    }
}
