<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

use App\Domains\Tenancy\Enums\PlatformSettingFormat;
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
     * @param  PlatformSettingFormat|null  $format  a shape the text must have, beyond its length
     *
     * @throws InvalidArgumentException when the field could not be described (see {@see PlatformSettingDefinition}), or has a format but is not text, or its fallback breaks the format
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
        public ?PlatformSettingFormat $format = null,
    ) {
        if (null !== $format && PlatformSettingType::Text !== $type) {
            throw new InvalidArgumentException(sprintf('Platform setting "%s" has a format but is not text.', $key));
        }

        $this->definition($fallback);

        if (is_string($fallback) && null !== $format && null !== $format->violation($fallback)) {
            throw new InvalidArgumentException(sprintf('The default of platform setting "%s" %s', $key, $format->violation($fallback)));
        }
    }

    /**
     * The definition with the installation's default: the configured value when it has the field's
     * type and, if the field has a format, passes it; otherwise the fallback.
     */
    public function definition(mixed $configured): PlatformSettingDefinition
    {
        $default = $this->hasType($configured) && $this->fitsFormat($configured) ? $configured : $this->fallback;

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
     * Why the value is refused: by the definition (type, length, bounds, choices), then by the format.
     */
    public function violation(PlatformSettingDefinition $definition, mixed $value): ?string
    {
        $violation = $definition->violation($value);

        if (null !== $violation || null === $this->format || ! is_string($value)) {
            return $violation;
        }

        return $this->format->violation($value);
    }

    private function fitsFormat(mixed $value): bool
    {
        return null === $this->format || ! is_string($value) || null === $this->format->violation($value);
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
