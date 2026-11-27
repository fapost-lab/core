<?php

declare(strict_types=1);

namespace App\Infrastructure\Solution;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\CommandActionType;
use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Commands\SolutionCommandRegistry;
use App\Domains\Flow\Contracts\CommandExtensionFactoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerFactoryInterface;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\TriggerTypeFactoryInterface;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use App\Domains\Solution\DTO\InstalledSolution;
use Closure;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Contracts\CoreRegistrarInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;
use Fapost\Foundation\Flow\Contracts\TriggerTypeInterface;
use Fapost\Foundation\Flow\Enums\NodeCategory;
use Fapost\Foundation\Flow\Enums\NodeConfigIssueSeverity;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * The Core side of {@see CoreRegistrarInterface} for one Solution.
 *
 * A Solution registers action handlers, node handlers, trigger types, command action types and chat commands, and
 * nothing else for now. Every other call fails
 * at once with a message naming the Solution, because a quiet no-op would show up at a client, not on the
 * author's first run. An action must be declared in the Solution's manifest (`extra.fapost.actions`), a node
 * type in `extra.fapost.nodes`, a trigger type in `extra.fapost.triggers`, a command action type in
 * `extra.fapost.command_types` and a chat command in `extra.fapost.commands`; that, together with the manifest rule that both start with the Solution id,
 * is what keeps one Solution out of another's (or Core's) namespace.
 */
final readonly class SolutionCoreRegistrar implements CoreRegistrarInterface
{
    public function __construct(
        private InstalledSolution $solution,
        private ActionHandlerRegistry $actions,
        private NodeHandlerRegistryInterface $nodes,
        private NodeHandlerFactoryInterface $nodeFactory,
        private TriggerTypeRegistry $triggerTypes,
        private TriggerTypeFactoryInterface $triggerTypeFactory,
        private CommandActionTypeRegistry $commandActionTypes,
        private SolutionCommandRegistry $commands,
        private CommandExtensionFactoryInterface $commandFactory,
        private BuiltinCommandsRegistry $builtinCommands,
        private LoggerInterface $logger,
    ) {
    }

    public function registerActionHandler(string $handlerClass): void
    {
        if (!is_a($handlerClass, ActionHandlerInterface::class, true)) {
            throw new LogicException(sprintf(
                '%s registers %s, which is not an action handler: it must implement %s.',
                $this->label(),
                $handlerClass,
                ActionHandlerInterface::class,
            ));
        }

        $id = $this->actions->idOf($handlerClass);

        if (!in_array($id, $this->solution->actions, true)) {
            throw new LogicException(sprintf(
                '%s registers action "%s" (%s), which extra.fapost.actions does not declare.',
                $this->label(),
                $id,
                $handlerClass,
            ));
        }

        $this->actions->register($handlerClass, $this->solution->id, $id);
    }

    public function registerNodeHandler(string $handlerClass): void
    {
        if (!is_a($handlerClass, NodeHandlerInterface::class, true)) {
            throw new LogicException(sprintf(
                '%s registers %s, which is not a node handler: it must implement %s.',
                $this->label(),
                $handlerClass,
                NodeHandlerInterface::class,
            ));
        }

        // The registry keeps the class; this throwaway instance only reads the handler's identity.
        $handler  = $this->nodeFactory->make($handlerClass);
        $type     = $handler->type();
        $category = $handler->category();

        if (!in_array($type, $this->solution->nodes, true)) {
            throw new LogicException(sprintf(
                '%s registers node type "%s" (%s), which extra.fapost.nodes does not declare.',
                $this->label(),
                $type,
                $handlerClass,
            ));
        }

        if (null === NodeCategory::tryFrom($category) && $category !== $this->solution->id) {
            throw new LogicException(sprintf(
                '%s registers node type "%s" (%s) with category "%s": category() must be a NodeCategory value or the Solution id "%s".',
                $this->label(),
                $type,
                $handlerClass,
                $category,
                $this->solution->id,
            ));
        }

        $this->nodes->register($handlerClass, $this->solution->id);
    }

    public function registerTriggerType(string $triggerTypeClass): void
    {
        if (!is_a($triggerTypeClass, TriggerTypeInterface::class, true)) {
            throw new LogicException(sprintf(
                '%s registers %s, which is not a trigger type: it must implement %s.',
                $this->label(),
                $triggerTypeClass,
                TriggerTypeInterface::class,
            ));
        }

        // The registry keeps the class; this throwaway instance only reads the type.
        $type = $this->triggerTypeFactory->make($triggerTypeClass)->type();

        if (!in_array($type, $this->solution->triggers, true)) {
            throw new LogicException(sprintf(
                '%s registers trigger type "%s" (%s), which extra.fapost.triggers does not declare.',
                $this->label(),
                $type,
                $triggerTypeClass,
            ));
        }

        $this->triggerTypes->register($triggerTypeClass, $this->solution->id);
    }

    public function registerCommandActionType(string $commandActionTypeClass): void
    {
        if (!is_a($commandActionTypeClass, CommandActionTypeInterface::class, true)) {
            throw new LogicException(sprintf(
                '%s registers %s, which is not a command action type: it must implement %s.',
                $this->label(),
                $commandActionTypeClass,
                CommandActionTypeInterface::class,
            ));
        }

        // The registry keeps the class; this throwaway instance only reads the type.
        $type = $this->commandFactory->makeActionType($commandActionTypeClass)->type();

        if (!in_array($type, $this->solution->commandTypes, true)) {
            throw new LogicException(sprintf(
                '%s registers command action type "%s" (%s), which extra.fapost.command_types does not declare.',
                $this->label(),
                $type,
                $commandActionTypeClass,
            ));
        }

        $this->commandActionTypes->register($commandActionTypeClass, $this->solution->id);
    }

    /**
     * A command Core took as built-in after the Solution declared it is skipped and logged, never fatal: the manifest
     * already refuses the built-in commands Foundation knows, so only a later Core release can make one collide.
     */
    public function registerCommand(string $commandClass): void
    {
        if (!is_a($commandClass, SolutionCommandInterface::class, true)) {
            throw new LogicException(sprintf(
                '%s registers %s, which is not a chat command: it must implement %s.',
                $this->label(),
                $commandClass,
                SolutionCommandInterface::class,
            ));
        }

        $command    = $this->commandFactory->makeCommand($commandClass);
        $name       = $command->command();
        $actionType = $command->actionType();

        if (!in_array($name, $this->solution->commands, true)) {
            throw new LogicException(sprintf(
                '%s registers command "%s" (%s), which extra.fapost.commands does not declare.',
                $this->label(),
                $name,
                $commandClass,
            ));
        }

        $builtIn = CommandActionType::tryFrom($actionType);

        if (CommandActionType::StartFlow === $builtIn || (null === $builtIn && !in_array($actionType, $this->solution->commandTypes, true))) {
            throw new LogicException(sprintf(
                '%s registers command "%s" (%s) with the action type "%s": it must be one of extra.fapost.command_types, send_message or terminate_session.',
                $this->label(),
                $name,
                $commandClass,
                $actionType,
            ));
        }

        if (CommandActionType::SendMessage === $builtIn && !$this->hasText($command->config()['text'] ?? null)) {
            throw new LogicException(sprintf(
                '%s registers command "%s" (%s) with send_message and no config "text": a string or a map of language to string is required.',
                $this->label(),
                $name,
                $commandClass,
            ));
        }

        // A type registered before the command is checked here; platform:update checks every command after boot.
        if (null === $builtIn && $this->commandActionTypes->has($actionType)) {
            foreach ($this->commandActionTypes->get($actionType)->validateConfig($command->config()) as $issue) {
                if (NodeConfigIssueSeverity::Error === $issue->severity) {
                    throw new LogicException(sprintf('%s registers command "%s" (%s) with a config "%s" refuses: %s', $this->label(), $name, $commandClass, $actionType, $issue->message));
                }
            }
        }

        if ($this->builtinCommands->has($name)) {
            $this->logger->warning('solution.command.builtin_taken', [
                'solution' => $this->solution->id,
                'command'  => $name,
                'class'    => $commandClass,
            ]);

            return;
        }

        $this->commands->register($commandClass, $this->solution->id);
    }

    public function registerDataAccessor(string $namespace, string $accessorClass): void
    {
        $this->unsupported('registerDataAccessor');
    }

    public function registerRagAdapter(string $provider, string $adapterClass): void
    {
        $this->unsupported('registerRagAdapter');
    }

    public function registerRoutes(Closure $routes): void
    {
        $this->unsupported('registerRoutes');
    }

    public function registerSchedule(Closure $schedule): void
    {
        $this->unsupported('registerSchedule');
    }

    public function registerMigrations(string $path): void
    {
        $this->unsupported('registerMigrations');
    }

    private function hasText(mixed $text): bool
    {
        if (is_string($text)) {
            return '' !== $text;
        }

        return is_array($text) && [] !== array_filter($text, static fn (mixed $value): bool => is_string($value) && '' !== $value);
    }

    private function unsupported(string $method): never
    {
        throw new LogicException(sprintf('%s calls %s(), which Core does not support for a Solution yet.', $this->label(), $method));
    }

    private function label(): string
    {
        return sprintf('Solution "%s" (%s)', $this->solution->id, $this->solution->package);
    }
}
