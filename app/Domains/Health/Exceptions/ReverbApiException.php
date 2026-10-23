<?php

declare(strict_types=1);

namespace App\Domains\Health\Exceptions;

use RuntimeException;

/**
 * Reverb's HTTP API could not be read. The message is safe to show: it never repeats the request URL, which carries
 * a signature, nor the app secret.
 */
final class ReverbApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    /**
     * No HTTP answer at all: refused, timed out, DNS.
     */
    public static function unreachable(): self
    {
        return new self('the connection failed or timed out');
    }

    public static function status(int $status): self
    {
        return new self("answered HTTP {$status}", $status);
    }

    public static function malformed(): self
    {
        return new self('answered with a body that is not Reverb\'s JSON');
    }
}
