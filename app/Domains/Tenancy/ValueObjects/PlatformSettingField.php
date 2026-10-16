<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

use Fapost\Foundation\Settings\DTO\PlatformSettingDefinition;
use Fapost\Foundation\Settings\Enums\PlatformSettingType;
use InvalidArgumentException;

/**
 * A platform-wide setting as a Core domain registers it in
 * {@see \App\Domains\Tenancy\Services\PlatformSettingRegistry}.
 *
 * It names the configuration key its default comes from (so an `.env` value keeps working as the
 * default), never the value: the registry outlives every request and job, and configuration must not
 * be held there. The value of that key is read per request or job and turned into a
 * {@see PlatformSettingDefinition} by {@see self::definition()}.
 */
final readonly class PlatformSettingField
{
    /**
     * @param  string  $configKey  where the installation's default is configured, such as "flow.execution.max_iterations"
     * @param  int|bool|string  $fallback  the default when that key is missing or of another type
     * @param  list<string>  $choices
     *
     * @throws InvalidArgumentException when the field could not be described (see {@see PlatformSettingDefinition})
     */
    public function __construct(
        public string $key,
        public PlatformSettingType $type,
        public string $configKey,
        public int|bool|string $fallback,
        public string $label,
        public string $description,
        public string $group,
        public ?string $unit = null,
        public ?int $min = null,
        public ?int $max = null,
        public array $choices = [],
        public bool $hostModeOnly = false,
    ) {
        $this->definition($fallback);
    }

    /**
     * The definition with the installation's default: the configured value when it has the field's
     * type, otherwise the fallback.
     */
    public function definition(mixed $configured): PlatformSettingDefinition
    {
        $default = $this->hasType($configured) ? $configured : $this->fallback;

        return new PlatformSettingDefinition(
            key: $this->key,
            type: $this->type,
            default: $default,
            label: $this->label,
            description: $this->description,
            group: $this->group,
            unit: $this->unit,
            min: $this->min,
            max: $this->max,
            choices: $this->choices,
            hostModeOnly: $this->hostModeOnly,
        );
    }

    /**
     * @phpstan-assert-if-true int|bool|string $value
     */
    private function hasType(mixed $value): bool
    {
        return match ($this->type) {
            PlatformSettingType::Integer => is_int($value),
            PlatformSettingType::Boolean => is_bool($value),
            PlatformSettingType::Text,
            PlatformSettingType::Choice => is_string($value),
        };
    }
}
