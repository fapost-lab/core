---
id: adr-20261123-solution-trigger-types
type: adr
status: proposed
date: 2026-11-23
domains:
  - flow
paths:
  - app/Domains/Flow/Registry/TriggerTypeRegistry.php
  - "app/Domains/Flow/TriggerTypes/**"
  - app/Jobs/Flow/FireRegisteredTriggerJob.php
  - app/Http/Controllers/Builder/TriggerTypesController.php
  - app/Infrastructure/Solution/SolutionCoreRegistrar.php
summary: "Why extensions register flow trigger types through a Foundation contract: pure TriggerTypeInterface with matches(), contactless runs with flow.trigger.* state, a global firer (accepted risk), tolerant reads of unknown types, and a 64-character type column"
---
# Extensions register trigger types through a Foundation contract; their runs are contactless, matched by the type's pure `matches()`, and fired by any extension code through one global firer

## Context

Spec `flow-automations`, phase 4: "trigger types are registrable (Solutions, forms) through a Foundation contract, as
node types are" (`adr-20261113-solution-node-types-and-state-namespace`); the phase is done when a test Solution
registers its own trigger type. Until now `FlowTriggerType` was a closed enum of five types, `flow_triggers.type` an
enum cast (a row of any other type failed to hydrate) and `string(32)`, too short for `<solution id>.<name>`.
Contactless runs (`StartAutomationRunJob`, fire keys, chain depth) already existed for Core events and webhooks.
Approved at the design gate by the owner ("Да, всё по рекомендациям", 2026-11-23): the recommended option of every
open decision.

## Decision

- **Contract (Foundation 0.46, experimental).** `Flow\Contracts\TriggerTypeInterface`: `type()`, `label()`,
  `configSchema()` (rendered by the builder's schema renderer; extensions ship no Vue), `validateConfig()` (a list of
  `NodeConfigIssue`, checked at validate/publish), `matches($config, $payload)`, `payloadFields()`; every method pure.
  `CoreRegistrarInterface::registerTriggerType()` registers by class; a Solution declares its types in
  `extra.fapost.triggers` (`<id>.<snake_case>`, at most 64 characters). `TriggerFirerInterface::fire($tenantId, $type,
  $fireKey, $payload)` is how extension code starts runs.
- **Registry.** `TriggerTypeRegistry` keeps `type => {class, owner}` and builds a type through a factory on every
  `get()`, frozen after boot. A Solution's type starts with its id, a Core type has no dot (for a future Core domain
  such as forms; whether forms use it is spec `forms-data-collection`'s call), a built-in name is never taken. The five
  built-in types stay the enum with their own code paths.
- **Tolerant read and an open column.** `FlowTrigger::type` reads as `FlowTriggerType` for a built-in type and as the raw string
  otherwise (`typeName()`, `builtInType()`); a row of a type nobody registers any more never breaks a screen. The
  column is an open `string(64)`: the PostgreSQL CHECK that allowed only the built-in types is dropped.
- **Validation and publish.** A registered type's own issues block or warn at `triggers.<id>.config[.<field>]`; a
  dotted type nobody registers is `trigger_type_not_installed`, any other unknown type `unknown_trigger_type`; a type
  of a Solution the tenant has not activated publishes with the warning `trigger_type_solution_inactive`. The model's
  save hook lets a dotted unregistered type through, so a row outlives its Solution. A registered trigger gives no
  contact (`TriggerContactContext`), and, not being `message`, needs `ManageAutomations` to publish.
- **Fire.** `QueuedTriggerFirer` refuses at the caller an unregistered type, an empty fire key, or a payload that is
  not JSON or larger than 64 KB; takes the chain depth from `EventChainContext` (+1; past 5 nothing is queued,
  `flow.trigger.chain_depth_exceeded`); namespaces the key `tt:<key>` (`tt:sha256:<hex>` when it does not fit); and
  queues `FireRegisteredTriggerJob` on `scheduled.triggers` after commit. In the tenant's context the job starts
  nothing while the owner Solution is inactive or the type is gone, and otherwise one `StartAutomationRunJob` per
  active trigger of the type whose config `matches()` the payload: state `flow.trigger.*` plus `flow.trigger_type`,
  the trigger's assistant, the fire's depth. A retry starts each run once (unique `(trigger_id, fire_key)`).

## Alternatives

- **Contact-giving types** (`fire()` with a contact id): rejected for now — cross-tenant or cross-assistant contacts,
  missing channels and collisions with the contact's active session are open questions; a companion interface can be
  added in a minor release without breaking the contract.
- **Moving the five built-in types onto the contract**: cleaner, but rewrites the message, webhook and event paths
  just merged, for no gain in this task.
- **A separate registrar interface for trigger types**: strictly compatible, but a second registration entry point;
  Core is the only implementation of `CoreRegistrarInterface`, so a new method in a minor release is acceptable.
- **Equality on declared match keys, or no filter at all**: no Solution code, but cannot say "at least", "one of";
  a pure `matches()` can, and no filter would push the work into every flow's condition node.
- **The payload in the Solution's own state namespace**: only for Solutions with `state: true`, and mixes trigger
  data with what its nodes write.
- **A firer per Solution that may fire only its own types**: stricter, but one more public method and per-process
  state. **Accepted risk:** any extension code may fire any registered type — Solution code is installed by the
  operator and trusted as much as its actions; Core checks that the type is registered and its Solution active.
  The same trust covers the tenant: `fire()` takes the tenant id explicitly (tenancy's rule of explicit context) and
  Core does not compare it with the tenant of a run the call happens in, so Solution code could start another
  tenant's flows — exactly as it could already write another tenant's data through its own jobs.
- **Named Solution events for the existing event trigger instead of trigger types**: less code, but contradicts the
  spec's decision; left for its own roadmap item.

## Consequences

- The contract is public from Foundation 0.46: a mistake in its shape is fixed by a deprecation in a minor release
  and a removal in a major one.
- Two limits of the guarantees are part of the contract: "once per fire key" holds while the first run is kept (run
  retention deletes it, and a later fire of the same key starts again), and the chain limit counts only fires made
  inside a run — a loop through an extension's own queued job is the extension's to break. `payloadFields()` is shown
  with the trigger; the expression pickers do not list `flow.trigger.*` yet.
- The widening migration rolls back only while every row holds a built-in type (its `down()` restores the CHECK).
- Rolling back Core's code leaves rows of Solution types; with the tolerant read they silently never fire.
- A new place that reads a trigger row's type must use `typeName()`/`builtInType()`, never `->type->value`.
