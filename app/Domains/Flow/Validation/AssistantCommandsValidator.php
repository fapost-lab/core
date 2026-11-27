<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use Fapost\Foundation\Flow\Enums\NodeConfigIssueSeverity;
use InvalidArgumentException;

/**
 * Validates {@code assistants.commands} JSONB content on save.
 *
 * Per ADR Message Routing § Validation:
 *  - Each command must start with '/' and contain no whitespace
 *  - Commands must be unique within an assistant
 *  - Action type must be a known {@see CommandActionType}, or a type a Solution
 *    registered ({@see CommandActionTypeRegistry}) whose `validateConfig()`
 *    finds no error in the entry's `config`; a dotted type nobody registers is
 *    refused as not installed. A registered type of a Solution the tenant has
 *    not activated is accepted: it simply does not match until it is.
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
        private CommandActionTypeRegistry $actionTypes,
    ) {
    }

    /**
     * @param  list<array<string, mixed>>  $commands
     * @param  mixed  $stored  the assistant's commands as stored before this save: an entry of a dotted type nobody
     *                         registers any more is kept when it is stored unchanged, so a removed Solution never
     *                         blocks saving the rest
     */
    public function validate(array $commands, mixed $stored = []): void
    {
        $seen = [];

        foreach ($commands as $index => $entry) {
            $position = "commands[{$index}]";

            $command = $entry['command'] ?? null;

            if (! is_string($command) || '' === $command) {
                throw new InvalidArgumentException("{$position}.command is required.");
            }

            if (! str_starts_with($command, '/')) {
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

            $typeName = (string) ($entry['type'] ?? '');
            $type     = CommandActionType::tryFrom($typeName);

            if (null === $type) {
                $this->validateRegistered($position, $command, $typeName, $entry['config'] ?? [], $stored);

                continue;
            }

            if (CommandActionType::StartFlow === $type) {
                $flowId = $entry['flow_id'] ?? null;
                if (! is_string($flowId) || '' === $flowId) {
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

    /**
     * A value with every map's keys sorted: a stored entry comes back from jsonb in its own key order.
     */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sorted(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function validateRegistered(string $position, string $command, string $typeName, mixed $config, mixed $stored): void
    {
        if (! $this->actionTypes->has($typeName)) {
            if (str_contains($typeName, '.') && $this->isStoredUnchanged($command, $typeName, $config, $stored)) {
                return;
            }

            if (str_contains($typeName, '.')) {
                throw new InvalidArgumentException("{$position}.type \"{$typeName}\" is not installed: no Solution registers it.");
            }

            throw new InvalidArgumentException("{$position}.type must be one of: " . $this->allowedTypes());
        }

        if ($this->builtins->has($command)) {
            throw new InvalidArgumentException("{$position} overrides built-in '{$command}' with the type {$typeName}; only response text is overridable.");
        }

        // A config the type accepted when it was saved stays saveable after the Solution tightened its rules: the
        // console cannot edit it, so refusing it would block every other setting.
        if ($this->isStoredUnchanged($command, $typeName, $config, $stored)) {
            return;
        }

        if (! is_array($config)) {
            throw new InvalidArgumentException("{$position}.config must be an object.");
        }

        /** @var array<string, mixed> $config */
        foreach ($this->actionTypes->get($typeName)->validateConfig($config) as $issue) {
            if ($issue instanceof NodeConfigIssue && NodeConfigIssueSeverity::Error === $issue->severity) {
                $field = null === $issue->field ? '' : '.' . $issue->field;

                throw new InvalidArgumentException("{$position}.config{$field}: {$issue->message}");
            }
        }
    }

    private function isStoredUnchanged(string $command, string $typeName, mixed $config, mixed $stored): bool
    {
        foreach (is_array($stored) ? $stored : [] as $entry) {
            if (is_array($entry)
                && ($entry['command'] ?? null) === $command
                && ($entry['type'] ?? null) === $typeName
                && self::sorted($entry['config'] ?? []) === self::sorted($config)) {
                return true;
            }
        }

        return false;
    }

    private function allowedTypes(): string
    {
        return implode(', ', [
            ...array_map(static fn (CommandActionType $t): string => $t->value, CommandActionType::cases()),
            ...$this->actionTypes->types(),
        ]);
    }
}
