<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Writers;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\History\HistoryWriterInterface;
use App\Domains\Flow\State\Exceptions\ReservedContactPathException;
use App\Domains\Flow\State\Exceptions\StructuralPathConflictException;
use App\Domains\Flow\State\Variables\VariableType;
use Closure;
use FAPost\Foundation\Flow\Contracts\ContactWriterInterface;
use FAPost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Concrete implementation of {@see ContactWriterInterface} bound to a single
 * contact for the duration of one node execution.
 *
 * Engine builds one writer per execute() call: it carries the resolved Contact
 * model, the current node id (for history), and the {@see HistoryWriterInterface}
 * resolved from the running flow_definition's logging_enabled flag.
 *
 * Each {@see write()} call is committed inside its own DB transaction (per ADR
 * State Writer Semantics — independent transactions Contact ↔ Session, retry
 * is the recovery story for partial state).
 */
final readonly class ContactWriter implements ContactWriterInterface
{
    /** Canonical contact columns that flow nodes are allowed to write. */
    private const array WRITABLE_COLUMNS = ['language', 'is_authenticated'];

    /** Identity columns / namespaces that flow nodes must never write. */
    private const array RESERVED_COLUMNS = ['id', 'tenant_id', 'external_id', 'platform'];

    private const array RESERVED_GROUPS = ['meta'];

    /**
     * @param  (Closure(): VariableSchemaRegistryInterface)|null  $schemaRegistryResolver
     *         Injected by FlowEngine so array variables use append + circular-buffer semantics.
     *         When null the writer falls back to replace for all paths (safe for legacy callers).
     */
    public function __construct(
        private Contact $contact,
        private string $sessionId,
        private string $nodeId,
        private ConnectionInterface $connection,
        private HistoryWriterInterface $historyWriter,
        private ?Closure $schemaRegistryResolver = null,
    ) {
    }

    public function write(string $path, mixed $value): void
    {
        if (! str_starts_with($path, 'contact.')) {
            throw new InvalidArgumentException("ContactWriter only handles 'contact.*' paths; got '{$path}'.");
        }

        $segments = explode('.', $path);
        $count    = count($segments);

        // contact.<key>           → 2 segments (canonical column or top-level attribute)
        // contact.<group>.<key>   → 3 segments (1-level group inside attributes)
        // anything deeper is rejected
        if ($count < 2 || $count > 3) {
            throw new InvalidArgumentException(
                "Contact path '{$path}' violates depth rules (allowed: contact.<key> or contact.<group>.<key>).",
            );
        }

        $head = $segments[1];

        if (in_array($head, self::RESERVED_COLUMNS, true)) {
            throw ReservedContactPathException::forPath($path);
        }

        if (3 === $count && in_array($head, self::RESERVED_GROUPS, true)) {
            throw ReservedContactPathException::forPath($path);
        }

        $tail = 3 === $count ? $segments[2] : null;

        $this->connection->transaction(function () use ($head, $tail, $value, $path): void {
            $oldValue = $this->readCurrent($head, $tail);
            $this->applyWrite($head, $tail, $value);
            $this->emitHistory($path, $oldValue, $value);
        });
    }

    private function readCurrent(string $head, ?string $tail): mixed
    {
        if (in_array($head, self::WRITABLE_COLUMNS, true)) {
            /** @var mixed $current */
            $current = $this->contact->getAttribute($head);

            return $current;
        }

        $attributes = is_array($this->contact->attributes) ? $this->contact->attributes : [];

        if (null === $tail) {
            return $attributes[$head] ?? null;
        }

        $group = $attributes[$head] ?? null;

        return is_array($group) ? ($group[$tail] ?? null) : null;
    }

    private function applyWrite(string $head, ?string $tail, mixed $value): void
    {
        if (in_array($head, self::WRITABLE_COLUMNS, true)) {
            if (null !== $tail) {
                throw new InvalidArgumentException(
                    "Canonical column '{$head}' does not support nested groups (contact.{$head}.{$tail}).",
                );
            }

            $this->contact->setAttribute($head, $value);
            $this->contact->save();

            return;
        }

        // For array-type variables, append rather than replace (spec §5.2).
        if ($this->isArrayVariable(null === $tail ? null : $head, null === $tail ? $head : $tail)) {
            $this->applyArrayAppend($head, $tail, $value);

            return;
        }

        $attributes = is_array($this->contact->attributes) ? $this->contact->attributes : [];

        if (null === $tail) {
            // contact.<head> = leaf
            if (isset($attributes[$head]) && is_array($attributes[$head])) {
                throw StructuralPathConflictException::leafVsGroup("contact.{$head}");
            }

            $attributes[$head] = $value;
            $this->contact->setAttribute('attributes', $attributes);
            $this->contact->save();

            return;
        }

        // contact.<head>.<tail>  → nested
        $existing = $attributes[$head] ?? null;

        if (null !== $existing && ! is_array($existing)) {
            throw StructuralPathConflictException::leafVsGroup("contact.{$head}");
        }

        /** @var array<string, mixed> $group */
        $group             = is_array($existing) ? $existing : [];
        $group[$tail]      = $value;
        $attributes[$head] = $group;
        $this->contact->setAttribute('attributes', $attributes);
        $this->contact->save();
    }

    /**
     * Append $value to the array at contact.<head> or contact.<head>.<tail>.
     * Applies circular-buffer logic when the array reaches max_size.
     */
    private function applyArrayAppend(string $head, ?string $tail, mixed $value): void
    {
        $group   = null === $tail ? null : $head;
        $name    = null === $tail ? $head : $tail;
        $maxSize = $this->resolveMaxSize($group, $name);

        $attributes = is_array($this->contact->attributes) ? $this->contact->attributes : [];

        if (null === $tail) {
            $current           = is_array($attributes[$head] ?? null) ? $attributes[$head] : [];
            $attributes[$head] = $this->appendWithCircularBuffer($current, $value, $maxSize);
        } else {
            $groupData         = is_array($attributes[$head] ?? null) ? $attributes[$head] : [];
            $current           = is_array($groupData[$tail] ?? null) ? $groupData[$tail] : [];
            $groupData[$tail]  = $this->appendWithCircularBuffer($current, $value, $maxSize);
            $attributes[$head] = $groupData;
        }

        $this->contact->setAttribute('attributes', $attributes);
        $this->contact->save();
    }

    /**
     * Append an element; shift out the oldest when the buffer is full.
     *
     * @param  list<mixed>  $array
     *
     * @return list<mixed>
     */
    private function appendWithCircularBuffer(array $array, mixed $value, int $maxSize): array
    {
        $array[] = $value;

        if ($maxSize > 0 && count($array) > $maxSize) {
            $array = array_values(array_slice($array, count($array) - $maxSize));
        }

        return $array;
    }

    /** Resolve max_size from the schema registry; default 100 when unknown. */
    private function resolveMaxSize(?string $group, string $name): int
    {
        if (null === $this->schemaRegistryResolver) {
            return 100;
        }

        $properties = ($this->schemaRegistryResolver)()->getProperties('contact', $group, $name);

        return isset($properties['max_size']) && is_int($properties['max_size'])
            ? $properties['max_size']
            : 100;
    }

    /**
     * Check whether the contact attribute at (group, name) is declared as an array type.
     * Canonical columns (language, is_authenticated) are never arrays.
     */
    private function isArrayVariable(?string $group, string $name): bool
    {
        if (null === $this->schemaRegistryResolver) {
            return false;
        }

        return VariableType::Array === ($this->schemaRegistryResolver)()->get('contact', $group, $name);
    }

    private function emitHistory(string $path, mixed $oldValue, mixed $newValue): void
    {
        $this->historyWriter->record(
            eventType: HistoryEventType::StateChange,
            tenantId: (string) $this->contact->tenant_id,
            sessionId: $this->sessionId,
            nodeId: $this->nodeId,
            path: $path,
            oldValue: $oldValue,
            newValue: $newValue,
        );
    }
}
