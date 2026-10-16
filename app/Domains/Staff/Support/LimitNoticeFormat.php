<?php

declare(strict_types=1);

namespace App\Domains\Staff\Support;

use Fapost\Foundation\Quota\DTO\LimitNotice;
use Fapost\Foundation\Quota\Enums\LimitKind;

/**
 * What the limit notifications to a tenant's admins have in common: the name of the limit, a number
 * written for people, and the button. Shared by `LimitReachedNotification` and
 * `LimitApproachingNotification`.
 */
final class LimitNoticeFormat
{
    /**
     * The limit's name in the current locale, or the English name when the key has no translation.
     */
    public static function label(string $key, string $fallbackLabel): string
    {
        $translation = 'limits.keys.' . $key;
        $label       = __($translation);

        return $label === $translation ? $fallbackLabel : $label;
    }

    public static function amount(int $value, LimitKind $kind): string
    {
        if (LimitKind::Bytes !== $kind) {
            return number_format($value, 0, '.', ' ');
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size  = (float) $value;
        $unit  = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            ++$unit;
        }

        return mb_rtrim(mb_rtrim(number_format($size, 1, '.', ' '), '0'), '.') . ' ' . $units[$unit];
    }

    /**
     * The operator's button when it gave a complete one, else the tenant's panel.
     *
     * @return array{label: string, url: string}
     */
    public static function action(?LimitNotice $notice, string $panelUrl): array
    {
        if (null !== $notice && null !== $notice->actionLabel && null !== $notice->actionUrl) {
            return ['label' => $notice->actionLabel, 'url' => $notice->actionUrl];
        }

        return ['label' => __('limits.open_panel'), 'url' => $panelUrl];
    }
}
