<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

use App\Domains\Assistant\Models\Assistant;

/**
 * Resolves an incoming text against the active assistant's command list.
 *
 * Lookup priority (per ADR Message Routing § Global Commands):
 *  1. Tenant assistant.commands (JSONB array)  — overrides built-in response text only
 *  2. Module-registered commands               — V1.x, not implemented yet
 *  3. Platform built-in (BuiltinCommandsRegistry)
 *
 * Returns null when no command matches; the routing pipeline then proceeds
 * to lock acquisition and normal flow execution.
 *
 * Match is exact, case-sensitive equality on the trimmed input.
 */
final readonly class CommandMatcher
{
    public function __construct(
        private BuiltinCommandsRegistry $builtins,
    ) {
    }

    public function match(string $text, Assistant $assistant): ?ResolvedCommand
    {
        $command = mb_trim($text);

        if ('' === $command || ! str_starts_with($command, '/')) {
            return null;
        }

        $tenantHit = $this->matchTenant($command, $assistant);

        if (null !== $tenantHit) {
            return $tenantHit;
        }

        return $this->matchBuiltin($command);
    }

    private function matchTenant(string $command, Assistant $assistant): ?ResolvedCommand
    {
        $commands = is_array($assistant->commands) ? $assistant->commands : [];

        foreach ($commands as $entry) {
            if ( ! is_array($entry)) {
                continue;
            }

            if (($entry['command'] ?? null) !== $command) {
                continue;
            }

            $type = CommandActionType::tryFrom((string) ($entry['type'] ?? ''));

            if (null === $type) {
                continue;
            }

            return new ResolvedCommand(
                command: $command,
                type: $type,
                response: $this->stringOrNull($entry['response'] ?? null),
                flowId: $this->stringOrNull($entry['flow_id'] ?? null),
                text: $this->stringOrNull($entry['text'] ?? null),
                origin: 'tenant',
            );
        }

        return null;
    }

    private function matchBuiltin(string $command): ?ResolvedCommand
    {
        $spec = $this->builtins->get($command);

        if (null === $spec) {
            return null;
        }

        return new ResolvedCommand(
            command: $command,
            type: $spec['type'],
            response: $spec['default_response'],
            origin: 'builtin',
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && '' !== $value) {
            return $value;
        }

        return null;
    }
}
