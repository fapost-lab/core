<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Exceptions;

use RuntimeException;

/**
 * Thrown when an operator reply reaches a thread the bot answers: replying needs the thread taken over first, checked
 * on the server whatever the screen showed.
 */
final class ConversationNotHeldByStaffException extends RuntimeException
{
}
