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
 * is metadata for the UI (text/number/phone/...) and does not affect path
 * resolution or runtime semantics.
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

    public function __construct(
        public string $name,
        public VariableStorage $storage,
        public ?string $group = null,
        public ?string $type = null,
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

        if ( ! is_string($name) || '' === $name) {
            return null;
        }

        if ( ! is_string($storage) || '' === $storage) {
            return null;
        }

        $storageEnum = VariableStorage::tryFrom($storage);

        if ( ! $storageEnum instanceof VariableStorage) {
            throw new InvalidArgumentException("Unknown variable storage: '{$storage}'.");
        }

        $group = $raw['group'] ?? null;

        if (null !== $group && ! is_string($group)) {
            throw new InvalidArgumentException('Variable group must be a string or null.');
        }

        if (is_string($group) && '' === $group) {
            $group = null;
        }

        $type = $raw['type'] ?? null;

        if (null !== $type && ! is_string($type)) {
            throw new InvalidArgumentException('Variable type must be a string or null.');
        }

        return new self(
            name: $name,
            storage: $storageEnum,
            group: $group,
            type: $type,
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
