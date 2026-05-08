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
                response: $this->stringOrLocaleMapOrNull($entry['response'] ?? null),
                flowId: $this->stringOrNull($entry['flow_id'] ?? null),
                text: $this->stringOrLocaleMapOrNull($entry['text'] ?? null),
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

        // Built-in response is always a translation key — the executor resolves
        // it through ContentTranslator using the contact's language so tenant
        // overrides in tenant_translations apply.
        return new ResolvedCommand(
            command: $command,
            type: $spec['type'],
            origin: 'builtin',
            responseKey: $spec['response_key'],
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value) && '' !== $value) {
            return $value;
        }

        return null;
    }

    /**
     * Tenants may store a flat string (legacy) or a locale map (after the
     * localization migration). The executor resolves either shape through
     * the translator, so we just pass the value through after rejecting
     * empty strings and non-string array entries.
     *
     * @return string|array<string, string>|null
     */
    private function stringOrLocaleMapOrNull(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return '' === $value ? null : $value;
        }

        if ( ! is_array($value)) {
            return null;
        }

        $clean = [];
        foreach ($value as $lang => $text) {
            if (is_string($lang) && is_string($text) && '' !== $text) {
                $clean[$lang] = $text;
            }
        }

        return [] === $clean ? null : $clean;
    }
}
