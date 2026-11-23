<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

use Fapost\Foundation\Auth\Enums\OneTimeCodeChannel;

/**
 * Authentication challenge type for the `auth_request` node.
 *
 * `auth_request@1` knows only {@see self::Basic}; `auth_request@2` adds the ownership checks: a shared phone number
 * and a one-time code sent by SMS or email.
 */
enum AuthMethod: string
{
    /** Compare a variable against an expected value; pass → raise the auth flag. */
    case Basic = 'basic';

    /** The contact shares their own phone number with the channel's contact button. */
    case Phone = 'phone';

    /** A one-time code sent by SMS, typed back by the contact. */
    case SmsCode = 'sms_code';

    /** A one-time code sent by email, typed back by the contact. */
    case EmailCode = 'email_code';

    /**
     * Builder dropdown options of a node version: value → localized label (admin-UI locale).
     *
     * @return array<string, string>
     */
    public static function options(int $version = 1): array
    {
        $cases = 1 === $version ? [self::Basic] : self::cases();

        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = (string) __('builder.nodes.auth_request.methods.' . $case->value);
        }

        return $options;
    }

    /**
     * The methods that wait for the contact's answer.
     *
     * @return list<string>
     */
    public static function waitingValues(): array
    {
        return [self::Phone->value, self::SmsCode->value, self::EmailCode->value];
    }

    /**
     * The channel a code method sends its code over; null for a method without a code.
     */
    public function codeChannel(): ?OneTimeCodeChannel
    {
        return match ($this) {
            self::SmsCode   => OneTimeCodeChannel::Sms,
            self::EmailCode => OneTimeCodeChannel::Email,
            default         => null,
        };
    }
}
