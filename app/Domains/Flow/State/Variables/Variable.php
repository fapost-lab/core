<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Variables;

use InvalidArgumentException;

/**
 * Description of a user variable as the flow author sees it in the UI.
 *
 * Compile-time representation; in runtime engine it is expanded into a
 * concrete state path through {@see VariableResolver}.
 *
 * Identity is the triple (storage, group, name). The optional {@see $type}
 * drives coercion in VariableResolver when reading values — unknown types
 * fall back to raw string (backward-compatible).
 */
final readonly class Variable
{
    /**
     * Reserved names that may never be used for user variables. Aligned
     * with reserved keys enforced by ContactWriter (canonical / identity
     * columns) plus a handful of platform reserved namespaces.
     */
    public const array RESERVED_NAMES = [
        'id',
        'channel_id',
        'tenant_id',
        'external_id',
        'meta',
        'language',
        'is_blocked',
        'created_at',
        'updated_at',
    ];

    /** Reserved group names — {@code meta} is owned by the platform. */
    public const array RESERVED_GROUPS = [
        'meta',
    ];

    /** Variable / group identifier pattern (alphanumeric + underscore, must not start with digit). */
    private const string IDENTIFIER_REGEX = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @param  array<string, mixed>  $properties  Extra schema metadata (e.g. max_size, item_type for arrays).
     */
    public function __construct(
        public string $name,
        public VariableStorage $storage,
        public ?string $group = null,
        public ?VariableType $type = null,
        public array $properties = [],
    ) {
        $this->assertValidIdentifier($name, 'name');

        if (in_array($name, self::RESERVED_NAMES, true)) {
            throw new InvalidArgumentException("Variable name '{$name}' is reserved and cannot be used.");
        }

        if (null !== $group) {
            $this->assertValidIdentifier($group, 'group');

            if (in_array($group, self::RESERVED_GROUPS, true)) {
                throw new InvalidArgumentException("Variable group '{$group}' is reserved and cannot be used.");
            }

            if (VariableStorage::Session === $storage) {
                throw new InvalidArgumentException('Session variables cannot use a group; groups are contact-only.');
            }
        }
    }

    /**
     * Construct a {@see Variable} from raw config payload (UI shape).
     *
     * Returns {@code null} when the payload is structurally incomplete
     * (missing name or unknown storage). Throws {@see InvalidArgumentException}
     * when fields are present but invalid (reserved keys, bad identifiers,
     * group depth violation) — distinguishes "absent" from "malformed".
     *
     * @param  array<string, mixed>  $raw
     */
    public static function tryFromArray(array $raw): ?self
    {
        $name    = $raw['name'] ?? null;
        $storage = $raw['storage'] ?? null;

        if (!is_string($name) || '' === $name) {
            return null;
        }

        if (!is_string($storage) || '' === $storage) {
            return null;
        }

        $storageEnum = VariableStorage::tryFrom($storage);

        if (!$storageEnum instanceof VariableStorage) {
            throw new InvalidArgumentException("Unknown variable storage: '{$storage}'.");
        }

        $group = $raw['group'] ?? null;

        if (null !== $group && ! is_string($group)) {
            throw new InvalidArgumentException('Variable group must be a string or null.');
        }

        if ('' === $group) {
            $group = null;
        }

        $rawType = $raw['type'] ?? null;
        $type    = null;

        if (null !== $rawType) {
            if (!is_string($rawType)) {
                throw new InvalidArgumentException('Variable type must be a string or null.');
            }

            // Unknown type strings (legacy / future) are silently ignored so that
            // existing flows remain loadable after a type enum change.
            $type = VariableType::tryFrom($rawType);
        }

        $rawProperties = $raw['properties'] ?? null;
        $properties    = is_array($rawProperties) ? $rawProperties : [];

        return new self(
            name: $name,
            storage: $storageEnum,
            group: $group,
            type: $type,
            properties: $properties,
        );
    }

    private function assertValidIdentifier(string $value, string $field): void
    {
        if (1 !== preg_match(self::IDENTIFIER_REGEX, $value)) {
            throw new InvalidArgumentException(
                "Variable {$field} '{$value}' must match identifier pattern (alphanumeric + underscore, not starting with a digit).",
            );
        }
    }
}
