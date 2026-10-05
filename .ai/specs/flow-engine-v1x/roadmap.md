# Roadmap — Flow engine V1.x

Destination: every limit the V1 node set documented as "later" is either built or dropped, and no
node spec carries a "not built" list.

## Phase 1 — Loops that fail loudly

Goal: a loop mistake is caught at publish, not by the iteration budget at runtime. Done when: a flow
with a never-changing while condition, a non-numeric static count or an iterator shadowing a session
variable gets a publish warning or error naming the node.

- [ ] Publish-time loop checks: iterator-vs-session variable conflict, unchanging while condition, static numeric count
- [ ] Loop-aware iteration budget instead of the shared `max_iterations = 100`
- [ ] `{{x.length}}` in templates and a `max_size` editor in the builder

## Phase 2 — Calls that can be retried safely

Goal: a `call` to a flaky endpoint can be retried without duplicating its effect. Done when: a call
retried after a timeout reaches the remote system with the same idempotency key and attempt number.

- [ ] Outbound idempotency for `call`: `attempt_number` and Redis dedup with a TTL above queue retention
- [ ] `call` retry with backoff (after: outbound idempotency — a retry without it duplicates side effects)
- [ ] `call` `strict_mapping` and generic `success_statuses`

## Phase 3 — Composable flows

Goal: a subflow behaves like a function. Done when: a parent passes values into a subflow and reads
its results without sharing session variables, against a pinned version of the child.

- [ ] Subflow `input_mapping` / `output_mapping`
- [ ] Subflow pinned to a target version
- [ ] `emit_event` `assistant_filter` on event triggers
- [ ] Design-time leaf-vs-group variable path check

## Phase 4 — Engine and node capabilities

Goal: the extension points the ADRs promised are real. Done when: a tenant can choose its expression
engine, and a Solution can register a bot command.

- [ ] Per-tenant expression engine: setting, boot validation, snapshot at save (ADR-08)
- [ ] Commands and command action types registered by a Solution (ADR-09) (after: spec `solution-activation-lifecycle` — registration needs the Core registrar)
- [ ] `auth_request` by phone, SMS and email
- [ ] fog: `notify` staff identity on a messenger and targeting by tag — no rollout has described the need yet

## Waves

1. Publish-time loop checks: iterator-vs-session variable conflict, unchanging while condition, static numeric count; Outbound idempotency for `call`: `attempt_number` and Redis dedup with a TTL above queue retention; Subflow `input_mapping` / `output_mapping`
2. Loop-aware iteration budget instead of the shared `max_iterations = 100`; `call` retry with backoff; Subflow pinned to a target version
3. `{{x.length}}` in templates and a `max_size` editor in the builder; `call` `strict_mapping` and generic `success_statuses`; `emit_event` `assistant_filter` on event triggers; Design-time leaf-vs-group variable path check
4. Per-tenant expression engine: setting, boot validation, snapshot at save (ADR-08); `auth_request` by phone, SMS and email
5. Commands and command action types registered by a Solution (ADR-09)

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
- A wave entry names an item by its title (the text before its first ` — `) or its task id,
  entries separated by `;` — `jig spec plan` reports an entry that names no item or several.
-->
