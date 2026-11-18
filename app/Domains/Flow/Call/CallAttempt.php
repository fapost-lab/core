<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

/**
 * How the attempt number of a `call` reaches a transport and the remote system.
 *
 * `CallContext` belongs to Foundation, so the number travels in {@see \Fapost\Foundation\Flow\Call\CallRequest::$options}
 * under {@see self::OPTION}. The call node always overwrites that key, so a flow author cannot set it.
 * A transport that talks to a remote system forwards it (the HTTP transport sets {@see self::HEADER}).
 *
 * @see CallExecutionLedgerInterface
 */
final class CallAttempt
{
    public const string OPTION = 'attempt_number';

    public const string HEADER = 'X-FaPost-Attempt-Number';

    private function __construct()
    {
    }
}
