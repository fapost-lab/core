<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandActionType;
use InvalidArgumentException;

/**
 * Validates {@code assistants.commands} JSONB content on save.
 *
 * Per ADR Message Routing § Validation:
 *  - Each command must start with '/' and contain no whitespace
 *  - Commands must be unique within an assistant
 *  - Action type must be a known {@see CommandActionType}
 *  - {@code start_flow} requires a non-empty flow_id
 *  - Built-in commands (/reset, /cancel) may be redeclared only with the
 *    canonical type (terminate_session) — only the response text is overridable
 *
 * Resolution of flow_id existence inside the tenant is delegated to the
 * caller (publish/save service has access to FlowDefinitionRepository).
 *
 * @see \App\Domains\Flow\Commands\CommandMatcher for runtime resolution
 */
final readonly class AssistantCommandsValidator
{
    public function __construct(
        private BuiltinCommandsRegistry $builtins,
    ) {
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     */
    public function validate(array $commands): void
    {
        $seen = [];

        foreach ($commands as $index => $entry) {
            $position = "commands[{$index}]";

            $command = $entry['command'] ?? null;

            if ( ! is_string($command) || '' === $command) {
                throw new InvalidArgumentException("{$position}.command is required.");
            }

            if ( ! str_starts_with($command, '/')) {
                throw new InvalidArgumentException("{$position}.command must start with '/'.");
            }

            if (preg_match('/\s/', $command)) {
                throw new InvalidArgumentException("{$position}.command must not contain whitespace.");
            }

            if (array_key_exists($command, $seen)) {
                throw new InvalidArgumentException(
                    "Duplicate command '{$command}' (also in commands[{$seen[$command]}]).",
                );
            }
            $seen[$command] = $index;

            $type = CommandActionType::tryFrom((string) ($entry['type'] ?? ''));

            if (null === $type) {
                throw new InvalidArgumentException("{$position}.type must be one of: " . $this->allowedTypes());
            }

            if (CommandActionType::StartFlow === $type) {
                $flowId = $entry['flow_id'] ?? null;
                if ( ! is_string($flowId) || '' === $flowId) {
                    throw new InvalidArgumentException("{$position}.flow_id is required when type=start_flow.");
                }
            }

            if ($this->builtins->has($command)) {
                $builtin = $this->builtins->get($command);

                if (null !== $builtin && $builtin['type'] !== $type) {
                    throw new InvalidArgumentException(sprintf(
                        "%s overrides built-in '%s' but changes its type from %s to %s; only response text is overridable.",
                        $position,
                        $command,
                        $builtin['type']->value,
                        $type->value,
                    ));
                }
            }
        }
    }

    private function allowedTypes(): string
    {
        return implode(', ', array_map(static fn (CommandActionType $t): string => $t->value, CommandActionType::cases()));
    }
}
