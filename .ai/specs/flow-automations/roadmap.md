# Roadmap — Flow automations

Destination: a flow starts from a message, a schedule, an event, a webhook or an API call, runs without a
conversational contact on safely kept credentials, reaches people through a contact, a tag, a segment or
a question it waits on, and every run is visible as one graph with the full data of each node.

## Phase 1 — Runs without a contact

Goal: the engine runs a flow that has no contact, safely and once. Done when: a test trigger starts a
contactless run that crosses several execution segments, a killed and retried segment continues the same
run instead of starting a second one, and a run past its deadline is moved to `failed`.

- [ ] `flow-contactless-sessions` — Sessions without a contact or assistant: `contact_id` and `assistant_id` nullable (history outlives an assistant), a run kind and `trigger_id` on the session, the state reader and writer fail with a typed error
- [ ] `flow-contact-context` — Contact context checked at publish: handlers declare `contact | none | any` (default `contact`), publish refuses a contact node, a `contact.*` expression or a contact write in a flow whose triggers give no contact; `contactId` nullable in a Foundation minor release (after: `flow-contactless-sessions` — the check guards a run that must first exist)
- [ ] `flow-segmented-execution` — Idempotent, segmented execution: a fire key under a unique index, segments re-dispatched as jobs, a queue and supervisor of their own (after: `flow-contactless-sessions` — the fire key lives on the session)
- [ ] `flow-run-deadline-sweeper` — Run deadline sweeper: overdue runs move to `failed` with a reason and free their trigger (after: `flow-segmented-execution` — the deadline is per run, the sweeper also picks up lost `pending` runs)
- [ ] `flow-run-retention` — Run retention: successful runs 7 days, failed 30, runtime settings, aligned with spec `data-lifecycle` (after: `flow-contactless-sessions` — the run kind decides the retention)

## Phase 2 — Schedule

Goal: a flow runs on a cron. Done when: reference automation 1 (a daily summary) is built in the builder
and passes e2e, a tick is never run twice, and a trigger that overlaps itself is skipped visibly.

- [ ] Several triggers per flow, live on publish — stable trigger ids in the definition, sync by id, `UNIQUE(flow_id)` dropped, activation moved from draft save to publish for every trigger type
- [ ] Schedule trigger — landlord registry of next fires, compare-and-set slot claim with the `pending` run in one transaction, skip-if-running on a `(tenant, trigger)` lock, no catch-up, jitter, DST semantics, one-minute floor (after: `flow-segmented-execution` — a scheduled run is a contactless run; after: Several triggers per flow, live on publish — `next_run_at` must survive a republish)
- [ ] Run rate breaker and quota seam — a Foundation "may this run start" contract and run counter for the SaaS shell, a Core breaker that pauses a trigger, a refusal counter and last reason on the trigger (after: Schedule trigger — the first trigger that fires on its own)

## Phase 3 — Credentials and permissions

Goal: an automation calls an external API without a secret in its config, and who may build, run and
read automations is set per permission. Done when: a `call` node authenticates with a stored credential
whose value no UI shows, and a content manager builds an automation on a credential the admin created.

- [ ] Credentials store — a Foundation contract shared by `call`, Solutions and connectors; Bearer, Basic, API key, OAuth2 client credentials; encrypted cast, write-only in the UI, referenced by id
- [ ] Automation and credential permissions — `ManageAutomations`, `ManageCredentials`, `UseCredentials`, `ViewRunPayloads`; the content manager gets all but `ManageCredentials`; a data migration for existing tenants (after: Credentials store — two of the permissions guard it)
- [ ] Credential and trigger changes in the activity log (after: spec `audit-log` — the staff activity log does not exist yet)

## Phase 4 — Events without a contact

Goal: things that happen in Core or a Solution start flows. Done when: reference automation 2 (a Core
event with no contact) passes e2e, a test Solution registers its own trigger type, and an automation that
re-raises its own event stops at depth 5.

- [ ] Core events start contactless runs — three to five Core domain events, `event_id` dedup per trigger, chain depth through a scoped run context, `flow.event.chain_depth_exceeded` (after: `flow-segmented-execution` — the event fire key is `(trigger, event_id)`)
- [ ] Registrable trigger types — a Foundation contract for Solutions and forms, a test Solution in the suite (after: Core events start contactless runs — the contract generalises the first non-message trigger path)
- [ ] `emit_event` `assistant_filter` on event triggers — moved here from `flow-engine-v1x` (after: Core events start contactless runs — the filter's meaning depends on assistant-less events)

## Phase 5 — Reaching people

Goal: a contactless run talks to people and waits for them. Done when: reference automation 3 (an
approval with a timeout) passes e2e, and a scheduled flow messages a segment through Broadcasting.

- [ ] `send_message` addressee — a contact by expression in a flow with no contact; publish refuses `send_message` without an addressee there (after: `flow-contact-context` — the addressee is what satisfies the check)
- [ ] Start a flow for a contact — contact by expression, the event-start collision rules, a `no_channel` outcome (after: `flow-contactless-sessions` — the starter is a contactless run)
- [ ] Ask a person and wait — the run parks, a question with buttons to a contact of an assistant, a token per button routes the answer to the run, a timeout branch, "already decided" on a late tap (after: `flow-segmented-execution` — parking and resume of a contactless run)
- [ ] Tag and segment addressees through Broadcasting — `send_message` to a tag or segment creates a broadcast; `input` towards many starts a flow per contact (after: spec `broadcast-flows` — Broadcasting cannot start a flow yet; after: `send_message` addressee — the same addressee field)
- [ ] fog: staff on a messenger as an addressee, and staff targeting by tag (moved here from `flow-engine-v1x`'s `notify` fog) — waits for the draft `notification-bot` to settle how a staff member is linked to a messenger

## Phase 6 — Run trace as a graph

Goal: any run — a conversation or an automation — is read as one graph. Done when: a conversation whose
buttons open further flows and subflows is shown on one screen with the taken path lit, each node's full
data a click away, and a loop of 1000 iterations stays readable.

- [ ] Node trace with full data — `NodeEntered`, `NodeFailed` and "node completed" in `flow_session_history`, outcome, duration, error, iteration, full input and output, secrets masked, 1 MB per node, erased with the contact
- [ ] Run graph page — the executed definition drawn with the builder's cards on `@vue-flow/core` and `elkjs`, branches and subflows as nested frames, loops as "×N" with an iteration switcher and small loops expanded, `ViewRunPayloads` for node data, live over Reverb or polling (after: Node trace with full data — the page draws what the trace recorded)

## Phase 7 — Webhook and API

Goal: other systems start flows. Done when: a third-party webhook starts a run and gets either 202 or
the flow's answer within 30 s, and an API call with a tenant token starts a run.

- [ ] Tenant API-token ADR — one token model for this spec and `mcp-server`
- [ ] Webhook trigger — served by Laravel, signature or secret, `(trigger, delivery id)` dedup, response mode 202 or "respond to webhook" with a 30 s limit (after: `flow-engine-v1x` outbound idempotency for `call` — a redelivered webhook would repeat calls; after: `flow-segmented-execution` — the delivery is the fire key)
- [ ] API trigger — start a flow with a tenant token and a payload (after: Tenant API-token ADR — the token model; after: Webhook trigger — the same inbound path)

## Phase 8 — The graph as a builder mode

Goal: a person edits a flow on the graph or on the tree, as they prefer. Done when: the builder offers both
modes over one definition, and the SaaS shell reports how many people use each.

- [ ] Graph builder mode — editing on the graph component, the choice kept per person (after: Run graph page — the same component, made editable)
- [ ] Builder mode usage in SaaS — a Core analytics event read by the shell through a Foundation contract (after: Graph builder mode — there is nothing to count before it)

## Phase 9 — Re-run and user-consent OAuth

Goal: the remaining conveniences. Done when: a failed run is re-run from the start with its payload, and a
credential is connected through an OAuth consent screen.

- [ ] Re-run from the start — same trigger payload, `RerunFlow` permission (after: Node trace with full data — the payload must be stored)
- [ ] OAuth2 with user consent — a callback route per tenant and scheduled token refresh (after: Credentials store — a new credential type)

## Waves

1. `flow-contactless-sessions`; Credentials store; Node trace with full data; Tenant API-token ADR; Several triggers per flow, live on publish
2. `flow-contact-context`; `flow-segmented-execution`; `flow-run-retention`; Automation and credential permissions; Run graph page
3. `flow-run-deadline-sweeper`; Schedule trigger; Core events start contactless runs; `send_message` addressee; Start a flow for a contact; Ask a person and wait; Graph builder mode; Re-run from the start; OAuth2 with user consent
4. Run rate breaker and quota seam; Registrable trigger types; `emit_event` `assistant_filter` on event triggers; Webhook trigger; Builder mode usage in SaaS; Credential and trigger changes in the activity log
5. API trigger; Tag and segment addressees through Broadcasting

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
