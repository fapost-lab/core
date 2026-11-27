<?php

declare(strict_types=1);

namespace App\Domains\Flow\Commands;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;

/**
 * Resolves an incoming text against the active assistant's command list.
 *
 * Lookup priority (per ADR Message Routing § Global Commands):
 *  1. Tenant assistant.commands (JSONB array)  — overrides built-in response text only
 *  2. Commands an activated Solution brings     ({@see SolutionCommandRegistry})
 *  3. Platform built-in (BuiltinCommandsRegistry)
 *
 * A tenant command whose type a Solution registered ({@see CommandActionTypeRegistry}), and a Solution's command,
 * match only while that Solution is active for the current tenant, asked on every match through
 * {@see SolutionAvailabilityInterface}; otherwise the entry is skipped like one of an unknown type, and the text
 * goes on to the next layer and, failing that, to the flow. A singleton: it holds registries and the port, never a
 * tenant's state.
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
        private SolutionCommandRegistry $solutionCommands,
        private CommandActionTypeRegistry $actionTypes,
        private SolutionAvailabilityInterface $availability,
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

        return $this->matchSolution($command) ?? $this->matchBuiltin($command);
    }

    private function matchTenant(string $command, Assistant $assistant): ?ResolvedCommand
    {
        $commands = is_array($assistant->commands) ? $assistant->commands : [];

        foreach ($commands as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['command'] ?? null) !== $command) {
                continue;
            }

            $type = CommandActionType::tryFrom((string) ($entry['type'] ?? ''));

            if (null === $type) {
                $registered = $this->matchRegisteredType($command, (string) ($entry['type'] ?? ''), $entry['config'] ?? [], 'tenant');

                if (null !== $registered) {
                    return $registered;
                }

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

    /**
     * A command an activated Solution brings: its action is one of the Solution's own types, or the built-in
     * `send_message` / `terminate_session` with the config's `text` / `response`.
     */
    private function matchSolution(string $command): ?ResolvedCommand
    {
        $owner = $this->solutionCommands->ownerOf($command);

        if (null === $owner || ! $this->availability->isAvailable($owner)) {
            return null;
        }

        $solutionCommand = $this->solutionCommands->get($command);
        $actionType      = $solutionCommand->actionType();
        $config          = $solutionCommand->config();

        return match (CommandActionType::tryFrom($actionType)) {
            CommandActionType::SendMessage => new ResolvedCommand(
                command: $command,
                type: CommandActionType::SendMessage,
                text: $this->stringOrLocaleMapOrNull($config['text'] ?? null),
                origin: 'solution',
                owner: $owner,
            ),
            CommandActionType::TerminateSession => new ResolvedCommand(
                command: $command,
                type: CommandActionType::TerminateSession,
                response: $this->stringOrLocaleMapOrNull($config['response'] ?? null),
                origin: 'solution',
                owner: $owner,
            ),
            // Registration refuses it: a Solution cannot name a tenant's flow.
            CommandActionType::StartFlow => null,
            null                         => $this->actionTypes->ownerOf($actionType) === $owner
                ? $this->matchRegisteredType($command, $actionType, $config, 'solution')
                : null,
        };
    }

    /**
     * A command whose action is a registered type, while the type's Solution is active for the tenant.
     */
    private function matchRegisteredType(string $command, string $actionType, mixed $config, string $origin): ?ResolvedCommand
    {
        $owner = $this->actionTypes->ownerOf($actionType);

        if (null === $owner || ! $this->availability->isAvailable($owner)) {
            return null;
        }

        return new ResolvedCommand(
            command: $command,
            type: null,
            origin: $origin,
            actionType: $actionType,
            config: is_array($config) ? $config : [],
            owner: $owner,
        );
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

        if (! is_array($value)) {
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
