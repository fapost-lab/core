<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Exceptions;

use RuntimeException;

/**
 * The channel an outbound message is addressed to no longer exists in the tenant (deleted since queuing).
 */
final class ChannelNotFoundException extends RuntimeException
{
}
