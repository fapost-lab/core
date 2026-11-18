<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Readers;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\State\Exceptions\ContactlessSessionException;
use Fapost\Foundation\Flow\Contracts\ScopedStateReaderInterface;
use Fapost\Foundation\Flow\Contracts\StateNamespacesInterface;

/**
 * Unified read surface across all state namespaces for a single node execution.
 *
 * Built per execution by the engine; carries the immutable session state JSON,
 * the resolved Contact model, and the data accessor registry. Returns null
 * for unknown paths, never throws on missing.
 *
 * Routing:
 *  - contact.<key>            → Contact column (id/tenant_id/external_id/platform/language)
 *                               or attributes[<key>] JSONB leaf, falling back to the
 *                               channel profile in `meta` for name/username/phone
 *                               and to the `platform` column for channel
 *  - contact.<group>.<key>    → attributes[<group>][<key>] JSONB nested
 *  - module.<name>.<...>      → DataAccessor registered under `module.<name>`, get(<...>)
 *  - flow|system|rag|call.*   → flow_sessions.state.<namespace>.<...>
 *  - <solution id>.*          → the same, for a namespace a Solution registered (StateNamespacesInterface)
 *
 * A contactless session ({@code $contact} null) reads every session namespace as usual, but `contact.*` and
 * `module.*` (an accessor is asked for a contact) throw {@see ContactlessSessionException}: the path is not missing,
 * it cannot exist for this kind of run, and a null would hide that until something downstream breaks.
 */
final readonly class ScopedStateReader implements ScopedStateReaderInterface
{
    /**
     * @param  array<string, mixed>  $sessionState  in-memory snapshot of flow_sessions.state for this execution
     */
    public function __construct(
        private array $sessionState,
        private ?Contact $contact,
        private DataAccessorRegistryInterface $accessors,
        private StateNamespacesInterface $namespaces,
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
            'contact'                       => $this->readContact($this->contactFor($path), $rest),
            'module'                        => $this->readModule($this->contactFor($path), $rest),
            'flow', 'system', 'rag', 'call' => $this->readSession($namespace, $rest),
            default                         => $this->namespaces->has($namespace) ? $this->readSession($namespace, $rest) : null,
        };
    }

    /**
     * @throws ContactlessSessionException
     */
    private function contactFor(string $path): Contact
    {
        return $this->contact ?? throw ContactlessSessionException::forRead($path);
    }

    private function readContact(Contact $contact, string $rest): mixed
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
            return $contact->getAttribute($head);
        }

        $attributes = is_array($contact->attributes) ? $contact->attributes : [];

        if (null === $tail) {
            return $attributes[$head] ?? $this->readProfileFallback($contact, $head);
        }

        $group = $attributes[$head] ?? null;

        if (! is_array($group)) {
            return null;
        }

        return data_get($group, $tail);
    }

    /**
     * Built-in profile fields the builder offers as `contact.<field>`. A value the flow saved in
     * attributes always wins (the caller checks it first); this only fills the gap from the channel
     * profile in `meta` (filled from the inbound payload) or the `platform` column.
     */
    private function readProfileFallback(Contact $contact, string $field): mixed
    {
        $meta = is_array($contact->meta) ? $contact->meta : [];

        $value = match ($field) {
            'name'     => $this->fullName($meta),
            'username' => $meta['username'] ?? null,
            'phone'    => $meta['phone'] ?? $meta['phone_number'] ?? null,
            'channel'  => $contact->platform,
            default    => null,
        };

        return '' === $value ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fullName(array $meta): ?string
    {
        $name = mb_trim((string) ($meta['name'] ?? ''));

        if ('' !== $name) {
            return $name;
        }

        $full = mb_trim((string) ($meta['first_name'] ?? '') . ' ' . (string) ($meta['last_name'] ?? ''));

        return '' === $full ? null : $full;
    }

    private function readModule(Contact $contact, string $rest): mixed
    {
        if ('' === $rest) {
            return null;
        }

        $segments = explode('.', $rest, 2);
        $prefix   = "module.{$segments[0]}";
        $key      = $segments[1] ?? null;

        if (null === $key || ! $this->accessors->has($prefix)) {
            return null;
        }

        return $this->accessors->resolve($prefix)->get(
            key: $key,
            contactId: (string) $contact->getKey(),
            tenantId: (string) $contact->tenant_id,
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
