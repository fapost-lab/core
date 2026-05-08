<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

/**
 * Hardcoded platform commands always available across all tenants.
 *
 * Per ADR Message Routing § Built-in Commands:
 *  - /reset   → terminate_session, default response "Диалог сброшен."
 *  - /cancel  → terminate_session, default response "Действие отменено."
 *
 * Tenant configuration may override the default response text but not the
 * action type or remove the command entirely.
 */
final class BuiltinCommandsRegistry
{
    /**
     * @return array<string, array{type: CommandActionType, default_response: string, overridable: list<string>}>
     */
    public function all(): array
    {
        return [
            '/reset' => [
                'type'             => CommandActionType::TerminateSession,
                'default_response' => 'Диалог сброшен.',
                'overridable'      => ['default_response'],
            ],
            '/cancel' => [
                'type'             => CommandActionType::TerminateSession,
                'default_response' => 'Действие отменено.',
                'overridable'      => ['default_response'],
            ],
        ];
    }

    public function has(string $command): bool
    {
        return array_key_exists($command, $this->all());
    }

    /**
     * Returns the built-in command's default specification, or null when not built-in.
     *
     * @return array{type: CommandActionType, default_response: string, overridable: list<string>}|null
     */
    public function get(string $command): ?array
    {
        return $this->all()[$command] ?? null;
    }
}
