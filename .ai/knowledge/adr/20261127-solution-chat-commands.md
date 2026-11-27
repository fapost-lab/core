---
id: adr-20261127-solution-chat-commands
type: adr
status: accepted
date: 2026-11-27
domains:
  - flow
  - solution
paths:
  - "app/Domains/Flow/Commands/**"
  - app/Infrastructure/Solution/SolutionCoreRegistrar.php
  - app/Domains/Flow/Validation/AssistantCommandsValidator.php
summary: "Why Solutions register chat command action types and ready-made commands through Foundation 0.50: an outcome instead of session access, unprefixed commands with catalog-level collisions, per-tenant matching between tenant and built-in commands, and stored entries of removed types kept"
reviewed_at: 2026-11-27
---
# Solutions add chat command action types and ready-made chat commands through Foundation; Core matches them per tenant between the tenant's and the built-in commands and carries out the outcome a Solution returns

## Context

ADR-09 (`adr-09-message-routing-concurrency`, § Global Commands) left "module-registered commands" for V1.x: a
three-layer lookup (tenant `assistant.commands`, module, platform built-in) and a closed `CommandActionType` enum
(`terminate_session`, `start_flow`, `send_message`). Roadmap item `solution-commands-registrar` of spec
`solution-activation-lifecycle` asked for commands and command action types registered by a Solution through the Core
registrar, taking effect only for tenants that activated it, gone on deactivation, a name collision failing at
registration. Foundation 0.49 had no contract for it. Approved at the design gate by the owner ("Одобряю", all seven
recommended answers, 2026-11-27).

## Decision

- **Contract (Foundation 0.50, experimental).** `Flow\Commands\CommandActionTypeInterface` (`type()`
  `<id>.<snake_case>` ≤ 64, `label()`, `configSchema()`, `validateConfig()`, `handle(CommandContext, config):
  CommandOutcome`), `SolutionCommandInterface` (`command()`, `actionType()`, `config()`, `label()`),
  `CoreRegistrarInterface::registerCommandActionType()` / `registerCommand()`, manifest fields
  `extra.fapost.command_types` and `extra.fapost.commands` (`/` + `[a-z0-9_]{1,32}`, the Telegram rule; reserved
  `/reset`, `/cancel`, `/start` — `/start` because Telegram sends it to every new contact).
- **No session access.** A command runs before the session lock, so `handle()` returns a `CommandOutcome` (reply,
  end the session, start a flow) and `GlobalCommandExecutor` carries it out with the steps of the built-in types.
  `CommandContext::$updateId` is the idempotency key the contract asks Solutions to use: `handle()` may run twice.
- **Registries.** `CommandActionTypeRegistry` (`type => {class, owner}`, owner always a Solution) and
  `SolutionCommandRegistry` (`command => {class, owner}`, refuses a built-in command), classes built per call, frozen
  after boot. A Solution command takes one of its own types or Core's `send_message` / `terminate_session`, never
  `start_flow` (a Solution cannot know a tenant's flow).
- **Names and collisions.** A slash command carries no id prefix. Two installed packages that declare one command are
  both left out of the catalog by `SolutionManifestValidator` (as an id collision is), so `platform:update` refuses
  them and no process dies at boot; inside one Solution a duplicate or an undeclared name fails the boot; a command
  Core takes as built-in in a later release is skipped and logged.
- **Lookup and tenant boundary.** `CommandMatcher`: tenant → Solution → built-in (a tenant entry shadows a Solution
  command). A Solution command, and a tenant entry of a registered type, match only while
  `SolutionAvailabilityInterface::isAvailable(owner)` for the current tenant, asked on every match; otherwise the text
  goes on to the flow. An active Solution that is not ready answers the assistant's busy message and queues the
  preparation, never runs `handle()`.
- **Settings.** `AssistantCommandsValidator` accepts a registered type whose `validateConfig()` finds no error, refuses
  a dotted type nobody registers as not installed, but keeps an entry stored unchanged (compared with keys sorted:
  jsonb) so a removed Solution, or one that tightened its rules, never blocks saving. The console shows such an entry
  read-only and the save keeps its stored config (the client's copy went through the input middleware); saving tells
  the staff user which commands will not run because their Solution is off. An editor for the config is follow-up work. `platform:update` reports declared but unregistered types and commands and a Solution
  command whose config its own type refuses.

## Alternatives

- **Only action types, no ready-made commands**: smaller contract, but a Solution could not bring `/menu`; rejected as
  half of ADR-09's module layer.
- **Commands as manifest data** (`commands: [{command, type, config}]`): fully declarative, but a config in
  `composer.json` cannot depend on code or translations; rejected (gate question 5).
- **An id prefix in the slash command**: no collisions by construction, but it is a name contacts type; rejected for a
  catalog-level collision check.
- **`handle()` gets the session or the engine**: more power, breaks "commands before the lock"; rejected.
- **A collision between packages fails the boot**: stricter, but one pair of packages would take every process down
  until failure isolation at boot exists; rejected.
- **Moving the three built-in types onto the contract**: cleaner, rewrites working paths for no gain (as with trigger
  types).

## Consequences

- The contract is public from Foundation 0.50: a mistake in its shape is a deprecation in a minor release and a removal
  in a major one; the reserved command list can shrink in a minor release, not grow without breaking a Solution.
- Rolling back Core's code leaves `assistants.commands` entries of dotted types: the matcher skips unknown types, so
  they silently never match.
- A Solution command is not listed in a channel's command menu (Telegram `setMyCommands`); that is separate work.
- A new place that reads `assistants.commands` must expect a dotted `type` with a `config` key and no built-in fields.
