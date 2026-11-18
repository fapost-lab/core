<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

/**
 * The flow a subflow node points at has no active definition. The node reports it as a failed result; any
 * other exception raised while the child runs is the child's or the parent's own and must not be taken for it.
 */
final class SubflowTargetInactiveException extends RuntimeException
{
}
