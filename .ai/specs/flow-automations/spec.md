# Flow automations — flows started by schedule, event, webhook and API, without a conversation

Depth: deep — it changes the Foundation node contract (`NodeExecutionContext`), the Flow runtime and its
session model, adds a public inbound endpoint on every tenant and a credentials store, and meets SaaS
quotas; most of these are expensive to take back. Two circles and an independent failure hunt
(2026-11-18).

## Idea

The owner, in their own words (2026-11-10 and 2026-11-18, translated from Russian): "We lack in the core
the flow mechanism you are talking about — on top of it any automation can be built, and that is exactly
what we are building." And: "change the current flow so it works not only for a message but also for
automation."

## Goal and problem

- Who is worse off without this, and how: today Flow is a conversational engine only — a session is one
  contact walking one flow, started by an inbound message or by `emit_event` inside a conversation. Every
  Solution that needs background work builds its own engine (Broadcasting already did). A builder user
  can pick and save `schedule`, `webhook` and `api` triggers (`TriggerConfig.vue`), and they never fire:
  `ScheduleTriggerResolver`, `WebhookTriggerResolver` and `ApiTriggerResolver` return `null`, and
  `DispatchFlowTriggerEventJob` drops every event without `source.contact_id`.
- What is true when the work is done: a flow starts from a message, a schedule, an event, a webhook or an
  API call; it runs without a conversational contact on credentials kept safely; it reaches people through
  one contact, a tag, a segment, or a question it waits on; and any run — a conversation or an automation —
  is visible as one graph with the full data of each node. Checked by three reference automations built in
  the builder from Core nodes only, covered by e2e:
  1. a daily summary: schedule → `call` to an external service → condition → message to the owner;
  2. a reaction to a Core event with no contact → a contactless run → an action;
  3. an approval: the run asks a person yes/no, parks, continues on the answer, and takes another branch
     on timeout;
  plus a test Solution in the test suite that registers its own trigger type.

## Stress test

- Hidden assumptions — "this holds only if …":
  - one engine serves both kinds of run only if every place that assumes a contact is found: handlers,
    and also expressions (`{{contact.*}}`), `ScopedStateReader` and `ContactWriter` — 77 references in
    32 Flow files at the time of writing;
  - third-party handlers stay safe only while the default declared context is "needs a contact";
  - the every-minute scheduler keeps up only while due triggers are found without switching every tenant
    schema.
- The main trade-off: one engine with a nullable contact (reuses parking, resume, subflows, logs,
  history, the builder) against a second engine for contactless runs (no risk to conversations, but two
  engines that drift apart within months).
- The weakest point: execution today is one in-process loop inside one queue job (`FlowEngine::start`,
  queue `scheduled.triggers`, timeout 120 s, 3 tries). Automations with several calls outlive that; the
  segmented, idempotent execution decided below is the largest change and the one most likely to leak.
- Failure modes — cause, what breaks, the signal that shows it (independent failure hunt, 2026-11-18):
  1. A retried job calls `start()` again — a second session and every `call` repeated; signal: two or
     three sessions per trigger tick, duplicates in the remote system.
  2. A worker killed mid-run leaves the session `active` forever; with skip-if-running the trigger never
     fires again and the flow cannot be deleted (`FlowDraftService.php:271`); signal: `last_run_at`
     advancing with no new sessions.
  3. A contact referenced from an expression or the state layer on a contactless run — TypeErrors only on
     the automation path.
  4. One scheduler pass over all tenant schemas outgrows a minute; with no catch-up, late means lost; the
     `scheduled.triggers` pool (2 processes) is shared with `sync.external`; signal: growing gap between
     `next_run_at` and the actual start, Horizon wait spikes on the hour.
  5. Domain events raised outside the engine (observers, listeners) carry no chain depth — an automation
     triggered by "contact updated" that updates a contact loops forever; N subscribers per level gives
     N^depth runs.
  6. `LockScope` is keyed by contact — contactless runs on an unrewritten path share one lock per tenant;
     the 30 s lock TTL lapses under a long blocking `call`.
  7. The answer to "ask a person" goes through ordinary inbound routing and is consumed by the person's
     conversational session or another waiting run; a tap after the timeout reaches nothing.
  8. `flow_sessions.assistant_id` is NOT NULL with cascade — deleting an assistant erases automation
     history; triggers without an assistant are skipped with only an info log.
  9. Moving activation from draft save to publish changes live triggers at release; several triggers per
     flow synced by `flow_id` lose `next_run_at` on republish.
  10. Runs and history grow without retention — one every-minute trigger is ~43k sessions a month.
  11. DST gaps and repeats; a tick consumed while its job is dropped (stopped tenant, Redis restart).
  12. A bare self-hosted install has no run-rate limit; under SaaS a refused start is invisible.
- Other shapes considered, and why this one: a second engine for automations — rejected, see the
  trade-off; background pipelines as plain Solution code only — rejected by the owner, because the
  platform is built as "conversations plus automations" and every Solution would rebuild the same engine;
  heavy row processing (tens of thousands of rows) on graph nodes — rejected, it stays a Solution action
  that a flow orchestrates.

## Scope and non-goals

- In scope:
  - contactless runs of the existing engine, with idempotent, segmented execution on a queue of their own;
  - triggers: schedule, events of Core and Solutions, webhook, API; several triggers per flow;
    registrable trigger types;
  - a credentials store as a Foundation contract, and the permissions around automations and credentials;
  - reaching people from a contactless run: an addressee on `send_message` (contact, tag, segment),
    "start a flow for a contact", "ask a person and wait";
  - a run trace for every session — conversations and automations — shown as a graph built from the
    builder's own node cards, and the same graph later as a second builder mode;
  - the Core side of quotas: a "may this run start" contract, a run counter, a rate breaker.
- Not doing:
  - portable flow packages (export, import, marketplace, "start from a package") — spec `flow-blueprints`
    (not written yet); this spec only promises that triggers and credential references export without
    values;
  - Broadcasting starting a flow per recipient — spec `broadcast-flows` (a draft today); this spec uses it;
  - staff on messengers — draft `notification-bot`;
  - fan-out of large lists into child runs — set aside below;
  - plan limits themselves — the SaaS shell's, through the contract above;
  - webhooks through the Go gateway — it serves Telegram only; third-party webhooks go through Laravel.

## Decisions

Boundaries with other specs:

- Two items move here from `flow-engine-v1x`: `emit_event` `assistant_filter` on event triggers (with
  contactless, assistant-less events the question "which assistant catches it" is a different question)
  and the fog "`notify` staff identity on a messenger" (the answer of a staff member on a messenger is the
  "ask and wait" mechanism). The `flow-engine-v1x` roadmap and spec are changed in the same pull request,
  so no item lives in two places.
- This spec depends on `flow-engine-v1x` for: outbound idempotency and retry of `call` (phase 2 there) —
  without it webhook and schedule retries duplicate side effects; subflow `input_mapping` /
  `output_mapping` (phase 3 there) — the contract for passing data into a flow started for a contact; a
  loop-aware iteration budget (phase 1 there) — automations over lists hit the shared limit of 100.
- One tenant API-token model, decided by one ADR shared with `mcp-server` — rejected: a token scheme per
  spec, because two auth schemes for one tenant drift.
- Credential changes and trigger changes are written to the staff activity log of spec `audit-log`.
- Run retention goes through spec `data-lifecycle`; a contact's traces are erased with the contact.

Run model:

- One table: `flow_sessions.contact_id` and `assistant_id` become nullable (`nullOnDelete` for the
  assistant, so history outlives it); a session gains a run kind and `trigger_id` — rejected: a separate
  `flow_runs` table, because parking, resume, subflows, logs and history would be duplicated.
- Every node handler declares the context it needs — `contact | none | any`, default `contact` — and
  publish refuses a flow whose triggers give no contact while a node, an expression or a state write
  needs one; the state reader and writer throw a typed error rather than a TypeError. `contactId` in
  Foundation's `NodeExecutionContext` becomes nullable in a minor release — rejected: a new handler
  version for every handler, because the default declaration keeps old handlers safe.
- An automation is a tenant object; an assistant is optional; nodes that reach a person name the
  assistant or channel explicitly.
- A flow may have several triggers, each with a stable id in the definition; sync goes by that id, and
  `next_run_at` is recomputed only when the cron or timezone changed — rejected: today's `UNIQUE(flow_id)`.
- Trigger types are registrable (Solutions, forms) through a Foundation contract, as node types are
  (adr-20261113 Solution node types) — rejected: a closed enum.
- All triggers go live on publish, not on draft save (this changes message triggers too).

Execution and duplicates:

- Start is idempotent: a run has a fire key — `(trigger, slot)` for a schedule, `(trigger, event_id)` for
  an event — under a unique index; a retried job finds the existing run and continues it.
- Execution is segmented: after N steps or seconds the engine saves its position and dispatches the next
  segment as a new job; no job outlives its timeout. Automations run on a queue and supervisor of their
  own, apart from `sync.external`.
- Claiming a schedule slot (compare-and-set on `next_run_at`) and creating the run as `pending` is one
  transaction; a stopped tenant claims nothing.
- Every run has a deadline (the Core maximum run duration); a sweeper moves overdue runs to `failed` with
  a reason, which frees the trigger.
- Contactless runs are serialized by a lock on `(tenant, trigger)`; work handed to a contact uses the
  existing `(tenant, contact, assistant)` lock. Two different triggers of one flow run in parallel — a
  "one run per flow" mode is not built until someone asks.
- A step repeated inside a run follows the existing rule (a handler's side effect is safe to repeat) and
  `call` idempotency from `flow-engine-v1x`.

Schedule:

- Cron and timezone per trigger; a floor of one minute in Core (a SaaS plan may raise it); if the previous
  run of the trigger is still going, the tick is skipped and logged; ticks missed during downtime are not
  caught up; deterministic jitter within the minute; a non-existent local time (DST spring) is skipped and
  a repeated hour fires once.
- One scheduler command a minute reads a flat landlord registry of next fires (`tenant`, `trigger`,
  `next_run_at`, precedent `webhook_registry`) and enters only the due tenants' schemas through
  `TenantSwitcher` — rejected: iterating every tenant schema each minute, because it falls behind.

Events:

- Sources: Core domain events (three to five chosen at analysis), events registered by Solutions,
  `emit_event` from any run including contactless ones.
- An event gets an `event_id` when published; a start is deduplicated by `(trigger, event_id)`.
- Chain depth: while a run executes, a scoped binding holds the current run's context, and domain events
  raised inside it inherit depth + 1; beyond depth 5 an event is dropped with
  `flow.event.chain_depth_exceeded`.

Quotas and safety:

- Core exposes a Foundation contract "may this run start" and a run counter; the SaaS shell enforces plan
  limits (spec `tenant-quotas`). Core itself keeps a maximum run duration, a maximum step count and a rate
  breaker — more than X runs of one trigger a minute pauses the trigger, visibly in the UI. A refused start
  creates no run; the trigger keeps a refusal counter and the last reason ("did not run: plan limit").

Reaching people:

- In a flow with no contact, `send_message` takes an addressee — a contact by expression, a tag or a
  segment; publish refuses it without one — rejected: hiding contact nodes in the palette, because an
  addressee makes them valid.
- A tag or a segment creates a broadcast through Broadcasting and continues; the outputs are the
  broadcast id and the recipient count; the broadcast shows in the broadcasts screen marked "from an
  automation" — rejected: a second mass-sending mechanism.
- "Start a flow for a contact": the contact by expression; the same collision rules as event starts
  today; no active channel is a `no_channel` outcome, not an exception.
- "Ask a person and wait": in phase one the addressee is a contact of an assistant (the owner subscribed
  to the bot); the run parks; only buttons are accepted, each carrying a token that routes the answer to
  the parked run, not to the contact's active session; a timeout takes another branch; a late tap gets
  "already decided". Staff on a messenger comes later through `notification-bot`; the addressee is shaped
  so a "staff" kind is added without a new handler version — rejected: building staff messenger identity
  now.
- `input` towards many people is "start a flow for each contact of a segment" through Broadcasting
  (spec `broadcast-flows`); each contact gets an ordinary session.

Credentials and permissions:

- A credentials store is a Foundation contract shared by `call`, Solutions and connectors — rejected: a
  store for `call` only, because the AI Solution's provider keys and connectors' tokens would build a
  second one.
- A credential is a tenant object with a name, a type and a value; types in the first phase: Bearer,
  Basic, API key in a header or a parameter, OAuth2 client credentials; the type is extensible. The value
  is encrypted with Laravel's encrypted cast on the application key and is never returned to the UI after
  saving — it can only be replaced. A node references a credential by id; the trace masks its value —
  rejected: per-tenant envelope encryption now, because there is no key rotation or KMS to run it.
- New permissions: `ManageAutomations` (triggers other than message), `ManageCredentials`,
  `UseCredentials` (pick a credential in a node without seeing or changing it), `ViewRunPayloads` (full
  node data in a trace; `ViewFlowSessions` shows the graph and statuses only), later `RerunFlow`. Admin
  gets them through `Permission::cases()`; the content manager gets all but `ManageCredentials` (they
  already publish flows, broadcast to every contact and read conversations); existing tenants get them by
  a data migration, precedent `grant_manage_channels_to_content_manager`.

Webhook and API:

- The webhook response mode is a trigger setting: answer 202 at once, or wait for a "respond to webhook"
  node or the end of the flow, at most 30 s, then 202 while the run continues. Third-party webhooks are
  served by Laravel; the Go gateway keeps Telegram.

Run trace:

- For every session, not only automations. The engine writes per node into `flow_session_history`:
  `NodeEntered` and `NodeFailed` (declared in `HistoryEventType`, never written today) and a "node
  completed" event, with the outcome handle, duration, error, loop iteration and the node's input and
  output — rejected: a separate trace table.
- Full input and output are kept, including a `call` response body; secrets are masked (authorization
  headers, credential values, variables marked secret, tokens in URLs); a hard limit of 1 MB per node,
  above it truncated with "truncated, N bytes"; retention as runs; erased with the contact.
- The run page draws the whole definition the run executed as a graph — branches, buttons and subflows
  expanded inline as nested frames, the taken path lit, untaken branches dimmed, a status on each card,
  a click opens the node's data — using the builder's own card components with `@vue-flow/core` and
  `elkjs` (dependencies agreed with the owner) — rejected: overlaying the trace on the builder's tree,
  because branches and button flows open as separate drill-down screens and a pass needs clicks through
  levels; and `dagre`, because it lays out nested groups worse.
- A loop node is drawn once with "×N" and an iteration switcher that recolours the path; small loops are
  expanded in full.
- A running trace updates over Reverb when broadcasting is enabled, by polling otherwise.
- The same graph becomes a second builder mode; each person chooses which builder to use, and the SaaS
  shell sees how many people use which (a Core analytics event read through a Foundation contract).

Retention:

- Successful runs are kept 7 days, failed ones 30, set in runtime settings, through spec
  `data-lifecycle`.

## Open questions

- Which three to five Core domain events come first — decides the first event triggers and the reference
  automation number 2.
- N steps or seconds per execution segment — decides the job length and the overhead of segmenting.
- The rate breaker's X runs per minute per trigger — decides how fast a loop is stopped against how often
  a legitimate burst is paused.
- Where the "respond to webhook" node's answer is held while the request waits — decides whether the
  synchronous mode needs a shared store between the web worker and the queue.
- Which existing tenants' message triggers change when activation moves to publish — decides whether the
  switch needs a one-time republish or a notice.

## Set aside

- Fan-out of large lists into child runs with backpressure — set aside because Broadcasting covers "each
  contact of a segment" and a Solution action covers row processing; reopen when the first tenant hits the
  run duration limit.
- A "one run per flow at a time" mode — until someone asks.
- Re-running a failed run from the failed node — needs every node to be safely repeatable; only "re-run
  from the start with the same trigger payload" is planned.
- A full "debug mode" capture beyond the 1 MB limit.
- Styling of node cards through a class in the node schema and a stylesheet set in the admin — a theme of
  its own.
- Per-tenant envelope encryption of credentials — when a client requires it.
- unexplored: AI nodes inside automations — an agent with tools from `ai-solution` on a schedule; the
  seams are described there, but the contactless run model was not checked against them.
- unexplored: a per-tenant rate limit on outbound `call`, so an automation cannot flood someone else's
  API — the breaker here is on the trigger, not on the call.

## Assumptions left untested

- A one-minute cron floor is enough for Core — taken at deep; a tenant asking for sub-minute polling
  tests it.
- A chain depth limit of 5 stops loops without cutting legitimate chains — a real chained automation
  tests it.
- 7 / 30 days of run retention fits tenants' debugging — the first support case about an older run tests
  it.
- The versioned export format of triggers and credential references belongs to `flow-blueprints`, not
  here — that spec's first circle tests it.
- The content manager may hold `ManageAutomations` and `ViewRunPayloads` without widening their reach —
  a tenant that splits content and automation staff tests it.
