# 01. State Model

## 1.1 Namespaces in the session snapshot and at runtime

Every node reads and writes through one set of namespaces. The canonical list is the enum
`Fapost\Foundation\Flow\Enums\StateNamespace` (see `.ai/knowledge/adr/0002-retire-core-state-primitives.md`).
Namespaces are **not shown** to the end user in the UI.

| Namespace | Owner | Persistence | Purpose |
|-----------|-------|-------------|---------|
| `system.*` | Engine and whitelisted nodes | session JSON | engine markers and platform facts, see 1.5 |
| `flow.*` | any node (user variables, `session` storage) | session JSON | session-scoped scratchpad |
| `rag.*` | `rag_query` node | session JSON | found, confidence, answer, intent of the last query |
| `call.*` | any node (the `call` node's response) | session JSON | response of the last call |
| `contact.*` | Contact model, through `ContactWriter` | persistent (Contact) | contact properties |
| `module.<name>.*` | `DataAccessorInterface` | external (module) | canonical module data, read-only |

`flow_sessions.state` holds only `system`, `flow`, `rag` and `call`. `contact.*` and `module.*` are
resolved through the reader when read and, for `contact.*`, written through `ContactWriter`; they are
never duplicated into session state.

**Persistence model (see ADR-0002):**

- Reads: `App\Domains\Flow\State\Readers\ScopedStateReader`, built per node execution over the
  in-memory session state, the `Contact` and the data accessor registry. An unknown path reads as
  `null`.
- Session writes: a handler returns flat `stateChanges` (`flow.x`, `system.y`);
  `FlowSessionPersister` validates the namespace against `SystemStateNamespacePolicy` and persists the
  state in one `UPDATE flow_sessions` per node, with optimistic-lock versioning.
- Contact writes: the handler calls `ContactWriterInterface::write()`; each write commits
  immediately in its own transaction.
- The earlier `ScopedStateWriter` / `StateWriter` layer was retired by ADR-0002 and no longer exists.

## 1.2 Contact namespace

Flat addressing in expressions and variable targets:

```
contact.<key>                    - a leaf in attributes (or a canonical column)
contact.<group>.<key>            - nested JSON in attributes
contact.meta.<key>               - platform data (read-only for flows)
```

Reading `contact.<key>` (`ScopedStateReader::readContact`):

1. If `<key>` is a canonical column (`id`, `tenant_id`, `external_id`, `platform`, `language`,
   `is_authenticated`) and there is no tail, return the model column.
2. Otherwise walk the `attributes` JSON path.
3. A non-object value on an intermediate segment gives `null`.
4. A missing key gives `null`.

Writing `contact.<key>` (`ContactWriter::write`, called by `assign`, `input`, `auth_request`, ...):

1. The path must start with `contact.`; depth is `contact.<key>` or `contact.<group>.<key>`. Violations
   throw `InvalidArgumentException`.
2. Reserved keys (`id`, `tenant_id`, `external_id`, `platform`) and the group `meta` throw
   `ReservedContactPathException` (a `RuntimeException`).
3. `language` and `is_authenticated` are writable canonical columns and take no nested group.
4. Anything else is written into `attributes`, creating the group on the way.
5. A leaf and a group on the same path throw `StructuralPathConflictException` (a `RuntimeException`).
6. Array-typed variables append, with a circular buffer, instead of replacing.

These are **runtime guards in `ContactWriter`**. They are not checked when the flow is saved; a
violation surfaces when the node runs and, being an exception from a handler, fails the session.

## 1.3 Nesting depth

Groups are one level deep at most: `contact.<group>.<field>`.

```
contact.name                OK
contact.form.input1         OK
contact.form.address.city   too deep (InvalidArgumentException from ContactWriter)
```

Session variables (`flow.*`) cannot use a group at all: a `Variable` with `session` storage and a
group is rejected when it is constructed.

## 1.4 Reserved keys

**For `contact` (ContactWriter):**

- Identity columns: `id`, `tenant_id`, `external_id`, `platform` (`RESERVED_COLUMNS`).
- Group: `meta` (owned by the webhook ingress: username, first name from the platform).
- Writable canonical columns: `language`, `is_authenticated`.

**For variable names (`Variable::RESERVED_NAMES`):** `id`, `channel_id`, `tenant_id`, `external_id`,
`meta`, `language`, `is_blocked`, `created_at`, `updated_at`. The reserved group is `meta`. A
user-defined variable cannot take these names.

## 1.5 `system.*` keys

Keys are constants in `App\Domains\Flow\State\SystemStateKeys`; which node types may write
`system.*` is `SystemStateNamespacePolicy` (`send_message`, `input`, `delay`, `notify`, `set_tag`).

| Key | Written by |
|-----|-----------|
| `system.started_at`, `system.retry_count`, `system.flow_definition_id`, `system.contact_id` | engine, at session start |
| `system.channel.*` (`id`, `type`, `bot_username`, `bot_handle`, `link`) | engine at session start, projected by `ChannelStateProjector` from the contact's most recently used active channel of the assistant; absent when no channel can be resolved |
| `system.language` | read by `LanguageResolver` as a session-level language override; the engine does not seed it |
| `system.sent_messages` | `send_message` (idempotency markers) |
| `system.send_message.timeout.*`, `.response.*`, `.dynamic_buttons` | `send_message` |
| `system.input.{nodeId}.retry_count` | `input` |
| `system.delay.{nodeId}.*` | `delay` |
| `system.delayed.{nodeId}.resume_at` | `FlowSessionPersister` (a `delayed(resumeAt)` result), outside the whitelist |
| `system.staff_notified.{nodeId}`, `system.contacts_notified.{nodeId}` | `notify` |
| `system.set_tag.{nodeId}` | `set_tag` |

Channel data is therefore read as `{{system.channel.bot_handle}}`, not through the `contact` namespace.

---

## Related

- [02-common-concepts.md](02-common-concepts.md)
- [../../diagrams/04-session-state-machine.md](../../diagrams/04-session-state-machine.md)
- `.ai/knowledge/adr/0002-retire-core-state-primitives.md`
