<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

/**
 * Hardcoded platform commands always available across all tenants.
 *
 * Per ADR Message Routing § Built-in Commands:
 *  - /reset   → terminate_session, response from `commands.reset.response`
 *  - /cancel  → terminate_session, response from `commands.cancel.response`
 *
 * Each built-in references a system translation key — the actual ack text is
 * resolved at runtime via {@see \App\Domains\Flow\Contracts\ContentTranslatorInterface},
 * so tenants can override the wording per language through `tenant_translations`
 * without touching the action contract itself.
 */
final class BuiltinCommandsRegistry
{
    /**
     * @return array<string, array{type: CommandActionType, response_key: string, overridable: list<string>}>
     */
    public function all(): array
    {
        return [
            '/reset' => [
                'type'         => CommandActionType::TerminateSession,
                'response_key' => 'commands.reset.response',
                'overridable'  => ['response'],
            ],
            '/cancel' => [
                'type'         => CommandActionType::TerminateSession,
                'response_key' => 'commands.cancel.response',
                'overridable'  => ['response'],
            ],
        ];
    }

    public function has(string $command): bool
    {
        return array_key_exists($command, $this->all());
    }

    /**
     * Returns the built-in command's specification, or null when not built-in.
     *
     * @return array{type: CommandActionType, response_key: string, overridable: list<string>}|null
     */
    public function get(string $command): ?array
    {
        return $this->all()[$command] ?? null;
    }
}
