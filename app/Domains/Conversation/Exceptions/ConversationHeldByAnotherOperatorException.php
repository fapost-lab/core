<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Exceptions;

use RuntimeException;

/**
 * Thrown when an operator replies to a thread another operator holds: only the holder speaks, checked on the server
 * whatever the screen showed (an administrator seizes the thread first).
 */
final class ConversationHeldByAnotherOperatorException extends RuntimeException
{
}
