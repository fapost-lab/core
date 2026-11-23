<?php

declare(strict_types=1);

namespace App\Domains\Flow\Auth;

/**
 * Normalises and masks the phone numbers and email addresses `auth_request@2` works with.
 *
 * A phone number is compared and sent in E.164 form: `+` and 8 to 15 digits, whatever spaces, dashes or brackets
 * it came with (Telegram shares numbers without the `+`). A mask keeps enough to recognise the destination in a
 * trace and no more: `+38050***67`, `j***@mail.com`.
 */
final class AuthDestination
{
    public static function normalizePhone(?string $raw): ?string
    {
        if (null === $raw) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = mb_substr($digits, 2);
        }

        $length = mb_strlen($digits);

        return $length >= 8 && $length <= 15 ? '+' . $digits : null;
    }

    public static function normalizeEmail(?string $raw): ?string
    {
        $email = mb_strtolower(mb_trim((string) $raw));

        return '' !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public static function maskPhone(string $phone): string
    {
        $length = mb_strlen($phone);

        if ($length <= 7) {
            return str_repeat('*', $length);
        }

        return mb_substr($phone, 0, 6) . '***' . mb_substr($phone, -2);
    }

    public static function maskEmail(string $email): string
    {
        $at = mb_strrpos($email, '@');

        if (false === $at || 0 === $at) {
            return '***';
        }

        return mb_substr($email, 0, 1) . '***' . mb_substr($email, $at);
    }
}
