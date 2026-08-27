<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\PersistentInlineButton;

/**
 * Manages registrations for keep-forever inline keyboard buttons.
 *
 * Each registration maps a callback_data payload (original_session_id + button_id)
 * back to the flow definition snapshot and node that sent the button, allowing
 * the orchestrator to re-enter the correct branch when the button is pressed
 * outside of or after the originating session.
 */
interface PersistentButtonRegistryInterface
{
    /**
     * Registers a set of buttons from a single send_message send as persistent.
     * Creates one row per button_id.
     *
     * @param  list<string>  $buttonIds  Button UUIDs to register (from node config)
     */
    public function register(
        string $tenantId,
        string $contactId,
        string $externalMessageId,
        string $originalSessionId,
        string $flowDefinitionId,
        string $nodeId,
        array $buttonIds,
    ): void;

    /**
     * Looks up a persistent button registration by the decoded callback payload.
     * Returns null when no matching registration exists.
     */
    public function find(
        string $tenantId,
        string $contactId,
        string $originalSessionId,
        string $buttonId,
    ): ?PersistentInlineButton;
}
