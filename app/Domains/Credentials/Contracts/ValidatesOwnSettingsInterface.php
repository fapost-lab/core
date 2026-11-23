<?php

declare(strict_types=1);

namespace App\Domains\Credentials\Contracts;

/**
 * A credential type with rules of its own for a setting, beyond what the field's kind says. The credential writer asks
 * after its own checks passed; the answer is a translated message that never carries the value.
 */
interface ValidatesOwnSettingsInterface
{
    /**
     * Why the value of the setting cannot be saved, or null when it can.
     */
    public function settingError(string $key, string $value): ?string;
}
