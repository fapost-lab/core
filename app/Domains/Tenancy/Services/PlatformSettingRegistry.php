<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\ValueObjects\PlatformSettingField;
use LogicException;

/**
 * The platform-wide settings Core knows: one field per key, registered by the domain that owns it.
 *
 * A worker-lifetime singleton holding only immutable fields (no configuration, no values). Core's
 * providers register while the application boots, then {@see self::freeze()} closes it (outside
 * testing), as {@see LimitRegistry} does. Packages and Solutions do not register here: a package's
 * setting lives in its own store (`adr-20261016-runtime-settings-in-database`).
 */
final class PlatformSettingRegistry
{
    /**
     * @var array<string, PlatformSettingField>
     */
    private array $fields = [];

    private bool $frozen = false;

    /**
     * @throws LogicException when the key is already registered or the registry is closed
     */
    public function register(PlatformSettingField $field): void
    {
        if ($this->frozen) {
            throw new LogicException(sprintf('PlatformSettingRegistry is frozen; cannot register "%s".', $field->key));
        }

        if (isset($this->fields[$field->key])) {
            throw new LogicException(sprintf('Platform setting "%s" is already registered.', $field->key));
        }

        $this->fields[$field->key] = $field;
    }

    public function find(string $key): ?PlatformSettingField
    {
        return $this->fields[$key] ?? null;
    }

    /**
     * @return list<PlatformSettingField> ordered by group, then key
     */
    public function all(): array
    {
        $fields = array_values($this->fields);

        usort($fields, static fn (PlatformSettingField $a, PlatformSettingField $b): int => [$a->group, $a->key] <=> [$b->group, $b->key]);

        return $fields;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
