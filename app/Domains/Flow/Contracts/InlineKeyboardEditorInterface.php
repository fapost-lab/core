<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Removes the inline keyboard from a previously sent message.
 * Best-effort: implementations must silently swallow failures.
 */
interface InlineKeyboardEditorInterface
{
    public function removeKeyboard(
        string $tenantId,
        string $contactId,
        string $sessionId,
        string $externalMessageId,
    ): void;
}
