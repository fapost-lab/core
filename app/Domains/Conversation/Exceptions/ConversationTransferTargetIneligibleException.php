<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Exceptions;

use RuntimeException;

/**
 * Thrown when a thread is transferred to someone who cannot take it: not active staff, no permission to reply, no
 * access to the assistant, or the holder themself.
 */
final class ConversationTransferTargetIneligibleException extends RuntimeException
{
}
