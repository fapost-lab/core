<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Enums;

/**
 * A shape a text platform setting must have beyond its length; see {@see \App\Domains\Tenancy\ValueObjects\PlatformSettingField}.
 */
enum PlatformSettingFormat: string
{
    /** An absolute http or https address, or nothing at all. */
    case Url = 'url';

    /**
     * Why the value does not have the shape, in the wording of the Foundation violations, or null when it does.
     * An empty value is accepted: it means "none".
     */
    public function violation(string $value): ?string
    {
        return match ($this) {
            self::Url => $this->urlViolation($value),
        };
    }

    private function urlViolation(string $value): ?string
    {
        if ('' === $value) {
            return null;
        }

        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (false !== filter_var($value, FILTER_VALIDATE_URL) && is_string($scheme) && in_array(mb_strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        return 'must be an http or https address, or empty.';
    }
}
