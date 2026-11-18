<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

/**
 * A subflow node is pinned to a version of the child flow that no longer exists (or never ran as a flow). The node
 * reports it as a failed result, apart from a target with no active definition at all.
 */
final class SubflowPinnedVersionMissingException extends RuntimeException
{
}
