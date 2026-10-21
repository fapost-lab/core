<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Models\PlatformSetting;
use App\Domains\Tenancy\Models\PlatformSettingEvent;
use App\Domains\Tenancy\ValueObjects\PlatformSettingField;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Settings\DTO\PlatformSettingDefinition;
use Fapost\Foundation\Settings\DTO\PlatformSettingValue;
use Fapost\Foundation\Settings\Exceptions\InvalidPlatformSettingsException;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\ConnectionResolverInterface;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Core's platform-wide settings: the only reader and writer of the landlord tables
 * `platform_settings` and `platform_setting_events`.
 *
 * Every setting is a field in {@see PlatformSettingRegistry} with a default from configuration (so
 * `.env`); a stored row replaces it and removing the row (a reset) brings the default back. The
 * stored rows are read once per instance, which is bound scoped: one request or one queue job reads
 * the table at most once and the next starts clean, so there is no cache to invalidate across
 * processes. A write drops this instance's copy. When the table cannot be read (migrations not
 * applied, the database down) every setting answers its default and the failure is reported once;
 * no setting here guards access, so none fails closed.
 *
 * Bound scoped, never as a singleton: it holds the config repository and the request's rows.
 */
final class PlatformSettings implements PlatformSettingsReaderInterface
{
    /** @var array<string, mixed>|null key => stored value; null until read, or when unreadable */
    private ?array $stored = null;

    private bool $attempted = false;

    public function __construct(
        private readonly PlatformSettingRegistry $registry,
        private readonly ConnectionResolverInterface $db,
        private readonly Config $config,
        private readonly ExceptionHandler $exceptions,
    ) {
    }

    public function integer(string $key): int
    {
        $value = $this->value($key);

        if (! is_int($value)) {
            throw new LogicException(sprintf('Platform setting "%s" is not an integer.', $key));
        }

        return $value;
    }

    public function boolean(string $key): bool
    {
        $value = $this->value($key);

        if (! is_bool($value)) {
            throw new LogicException(sprintf('Platform setting "%s" is not a boolean.', $key));
        }

        return $value;
    }

    public function text(string $key): string
    {
        $value = $this->value($key);

        if (! is_string($value)) {
            throw new LogicException(sprintf('Platform setting "%s" is not text.', $key));
        }

        return $value;
    }

    /**
     * The value in effect: the stored one when it is still accepted, otherwise the default.
     *
     * @throws LogicException when the key is not registered
     */
    public function value(string $key): int|bool|string
    {
        $definition = $this->definition($key);

        return $this->storedValue($definition) ?? $definition->default;
    }

    /**
     * @throws LogicException when the key is not registered
     */
    public function definition(string $key): PlatformSettingDefinition
    {
        $field = $this->registry->find($key) ?? throw new LogicException(sprintf('"%s" is not a platform setting.', $key));

        return $this->describe($field);
    }

    /**
     * @return list<PlatformSettingDefinition> ordered by group, then key
     */
    public function definitions(): array
    {
        return array_map($this->describe(...), $this->registry->all());
    }

    /**
     * @return list<PlatformSettingValue> in the order of {@see self::definitions()}
     */
    public function values(): array
    {
        return array_map(function (PlatformSettingDefinition $definition): PlatformSettingValue {
            $stored = $this->storedValue($definition);

            return new PlatformSettingValue($definition->key, $stored ?? $definition->default, $definition->default, null !== $stored);
        }, $this->definitions());
    }

    /**
     * Stores the given values and records every one that changed; a null removes the stored value so
     * the default applies again. Keys left out are not changed.
     *
     * @param  array<string, mixed>  $changes  key => new value
     * @param  string  $actor  who made the change: "user:<id>", "operator:<id>", "cli:<name>"
     *
     * @throws InvalidPlatformSettingsException when a key is unknown or a value is refused; nothing was written
     * @throws InvalidArgumentException when the actor is empty
     */
    public function update(array $changes, string $actor): void
    {
        if ('' === mb_trim($actor)) {
            throw new InvalidArgumentException('A platform setting change needs an actor.');
        }

        $violations = [];
        $accepted   = [];

        foreach ($changes as $key => $value) {
            $field = $this->registry->find($key);

            if (null === $field) {
                $violations[$key] = 'is not a platform setting.';

                continue;
            }

            $violation = null === $value ? null : $field->violation($this->describe($field), $value);

            if (null === $violation) {
                $accepted[$key] = $value;
            } else {
                $violations[$key] = $violation;
            }
        }

        if ([] !== $violations) {
            throw InvalidPlatformSettingsException::because($violations);
        }

        $now = CarbonImmutable::now();

        $this->db->connection('landlord')->transaction(function () use ($accepted, $actor, $now): void {
            foreach ($accepted as $key => $new) {
                // Locked, so two saves at once cannot both record the same old value.
                $row = PlatformSetting::query()->where('key', $key)->lockForUpdate()->first();
                $old = $row?->value;

                if ($old === $new) {
                    continue;
                }

                if (null === $new) {
                    $row?->delete();
                } else {
                    PlatformSetting::query()->updateOrCreate(['key' => $key], ['value' => $new, 'updated_at' => $now, 'updated_by' => $actor]);
                }

                PlatformSettingEvent::query()->create([
                    'key'        => $key,
                    'old_value'  => $old,
                    'new_value'  => $new,
                    'changed_by' => $actor,
                    'changed_at' => $now,
                ]);
            }
        });

        $this->stored    = null;
        $this->attempted = false;
    }

    private function describe(PlatformSettingField $field): PlatformSettingDefinition
    {
        return $field->definition($this->config->get($field->configKey));
    }

    /**
     * The stored value when there is one the definition still accepts; null otherwise (a value stored
     * before the bounds narrowed falls back to the default).
     */
    private function storedValue(PlatformSettingDefinition $definition): int|bool|string|null
    {
        $stored = $this->rows()[$definition->key] ?? null;

        $field = $this->registry->find($definition->key);

        if (null === $stored || null === $field || null !== $field->violation($definition, $stored)) {
            return null;
        }

        /** @var int|bool|string $stored */
        return $stored;
    }

    /**
     * @return array<string, mixed> key => stored value; empty when the table cannot be read
     */
    private function rows(): array
    {
        if ($this->attempted) {
            return $this->stored ?? [];
        }

        $this->attempted = true;

        $load = static fn (): array => PlatformSetting::query()
            ->get(['key', 'value'])
            ->mapWithKeys(static fn (PlatformSetting $setting): array => [$setting->key => $setting->value])
            ->all();

        try {
            $landlord = $this->db->connection('landlord');

            // Inside a caller's transaction the read runs in a savepoint: on PostgreSQL a failed read would
            // otherwise abort the caller's transaction even though the settings answer their defaults.
            $this->stored = $landlord->transactionLevel() > 0 ? $landlord->transaction($load) : $load();
        } catch (Throwable $e) {
            $this->exceptions->report($e);
            $this->stored = null;
        }

        return $this->stored ?? [];
    }
}
