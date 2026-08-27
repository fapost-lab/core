<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Support;

use libphonenumber\PhoneNumberUtil;
use Locale;

/**
 * Source of truth for the selectable country list used by assistant phone
 * configuration. Built from libphonenumber's supported regions so validation
 * and the UI options always agree on which ISO codes exist.
 */
final class CountryCatalog
{
    /** @var array<string, string>|null  Memoised `ISO => "Name (+dial)"` map. */
    private static ?array $cache = null;

    /**
     * All selectable countries as `ISO alpha-2 => "Name (+dial)"`, sorted by name.
     *
     * @return array<string, string>
     */
    public function options(): array
    {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $util    = PhoneNumberUtil::getInstance();
        $options = [];

        foreach ($util->getSupportedRegions() as $region) {
            $code           = (string) $region;
            $dial           = $util->getCountryCodeForRegion($code);
            $options[$code] = sprintf('%s (+%d)', $this->name($code), $dial);
        }

        asort($options);

        return self::$cache = $options;
    }

    /**
     * Display label for one ISO code (e.g. `UA` → "Ukraine (+380)"). Falls back
     * to the raw code when the country is not recognized.
     */
    public function label(string $code): string
    {
        $code = mb_strtoupper($code);

        return $this->options()[$code] ?? $code;
    }

    public function isValid(string $code): bool
    {
        return isset($this->options()[mb_strtoupper($code)]);
    }

    /**
     * Map a list of ISO codes to `{value, label}` option objects for the builder
     * frontend (preserving the given order, dropping unknown codes).
     *
     * @param  list<string>  $codes
     *
     * @return list<array{value: string, label: string}>
     */
    public function toOptions(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            if (! is_string($code) || ! $this->isValid($code)) {
                continue;
            }
            $upper = mb_strtoupper($code);
            $out[] = ['value' => $upper, 'label' => $this->label($upper)];
        }

        return $out;
    }

    private function name(string $code): string
    {
        $name = Locale::getDisplayRegion('-' . $code, 'en');

        return '' !== $name ? $name : $code;
    }
}
