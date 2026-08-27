<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Authentication challenge type for the `auth_request` node.
 *
 * Only {@see self::Basic} (variable comparison) is implemented today; richer
 * challenges (phone share, SMS/email code) are added as additional cases once
 * the AccessControl Feature lands, without changing the node's outputs.
 */
enum AuthMethod: string
{
    /** Compare a variable against an expected value; pass → raise the auth flag. */
    case Basic = 'basic';

    /**
     * Builder dropdown options: value → localized label (admin-UI locale).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Basic->value => (string) __('builder.nodes.auth_request.methods.basic'),
        ];
    }
}
