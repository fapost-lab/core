<?php

declare(strict_types=1);

namespace App\Domains\Flow\Routing;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;

/**
 * Applies the assistant's {@code busy_message} when an inbound message has
 * to be dropped because the session is locked or paused. Silent-drop
 * decisions just no-op here (kept centralised so future analytics hooks
 * have a single place to plug in).
 *
 * Per ADR Message Routing § Drop Policy:
 *   - Lock acquisition timeout / paused session → busy notice
 *   - Active state (race) / paused_subflow      → silent drop
 */
final readonly class DropPolicy implements DropPolicyInterface
{
    private const string DEFAULT_BUSY_MESSAGE = 'Бот занят, попробуйте через несколько секунд.';

    public function __construct(
        private FallbackMessageServiceInterface $messenger,
    ) {
    }

    public function applyBusy(Contact $contact, Assistant $assistant): void
    {
        $custom  = is_string($assistant->busy_message ?? null) ? mb_trim((string)$assistant->busy_message) : '';
        $message = '' !== $custom ? $custom : self::DEFAULT_BUSY_MESSAGE;

        $this->messenger->send($contact, (string)$assistant->getKey(), $message);
    }

    public function applySilent(Contact $contact, Assistant $assistant): void
    {
        // Intentionally a no-op. Reserved for analytics emission once dashboards land.
    }
}
