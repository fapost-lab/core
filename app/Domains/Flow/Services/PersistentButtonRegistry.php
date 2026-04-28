<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\PersistentButtonRegistryInterface;
use App\Domains\Flow\Models\PersistentInlineButton;
use Illuminate\Support\Str;

/**
 * Eloquent-backed persistent button registry.
 *
 * Inserts one row per button on registration and performs a single indexed lookup on resolution.
 */
final class PersistentButtonRegistry implements PersistentButtonRegistryInterface
{
    public function register(
        string $tenantId,
        string $contactId,
        string $externalMessageId,
        string $originalSessionId,
        string $flowDefinitionId,
        string $nodeId,
        array $buttonIds,
    ): void {
        if ([] === $buttonIds) {
            return;
        }

        $now  = now();
        $rows = [];

        foreach ($buttonIds as $buttonId) {
            $rows[] = [
                'id'                  => Str::ulid()->toRfc4122(),
                'tenant_id'           => $tenantId,
                'contact_id'          => $contactId,
                'external_message_id' => $externalMessageId,
                'original_session_id' => $originalSessionId,
                'flow_definition_id'  => $flowDefinitionId,
                'node_id'             => $nodeId,
                'button_id'           => $buttonId,
                'created_at'          => $now,
            ];
        }

        PersistentInlineButton::query()->insert($rows);
    }

    public function find(
        string $tenantId,
        string $contactId,
        string $originalSessionId,
        string $buttonId,
    ): ?PersistentInlineButton {
        return PersistentInlineButton::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('original_session_id', $originalSessionId)
            ->where('button_id', $buttonId)
            ->first();
    }
}
