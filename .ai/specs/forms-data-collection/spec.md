# Forms / Data Collection

Depth: normal — this is a migration of an already-accepted plan (Milestone 9 in
`docs/platform/ROADMAP.md`), not a fresh idea; the decisions below were made when that
milestone was designed, and this spec only carries them into Jig format.

## Idea

Collect structured data through a web form (a Telegram Mini App or a hosted page) instead
of a chain of `input` nodes in the chat. A chain of `input` nodes gathers a questionnaire
one field per message: slow for the contact, cumbersome to build in the flow builder, and
limited to "one field at a time" validation. A form closes the same scenario on one screen:
normal controls, client- and server-side validation against a single schema, edits before
submission, and an acceptable look.

## Goal and problem

- Who is worse off without this, and how: a contact filling out a multi-field
  questionnaire through a chain of `input` nodes spends one message per field and cannot
  see or correct earlier answers before submitting; the flow author has to build and
  maintain a long node chain with validation that only ever covers one field at a time.
- What is true when the work is done: a `form` node sends one screen (Telegram Mini App
  `web_app` button, or a hosted-page link for other channels) that collects several
  fields with normal controls, validates them against one schema on both client and
  server, lets the contact edit before submitting, and resumes the flow session on
  submission with the answers available to the flow.

## Stress test

- Hidden assumptions — "this holds only if …": the existing TMA scaffold
  (`routes/tma.php`, `resources/js/tma` with its router and `TmaFormRenderer`,
  `InputExpectedType`, `MediaIngestor`, `ContactWriterInterface`) is close enough to what
  Phase 1/2 need that it is extended rather than replaced; today `TmaFormController` and
  `TmaAuthMiddleware` are stubs (hardcoded form, 401 outside `local`).
- The main trade-off: keeping the form node, the external-event resume path and the
  builder override in Core (not a Solution) buys a working feature now, at the cost of
  growing Core's flow-engine and builder surface for a feature that, per
  `docs/roadmap.md`, has no anchor in the product brief.
- The weakest point: `docs/roadmap.md` § Out of scope lists "Web forms data collection"
  explicitly — "a product feature with no anchor in the brief; it neither tests
  extensibility nor shortens the first-contact path" — so this spec migrates a plan that
  the product roadmap currently does not prioritize.
- Failure modes — cause, what breaks, the signal that shows it:
  - A resend of the same submission (double network send, retry) without an idempotency
    marker on the submission id would advance the flow session twice.
  - A hosted-page link token that is reusable (not single-use) is the only protection on
    that path, since there is no Telegram initData HMAC to back it up; a leaked link
    would let anyone submit the form.
  - A synthetic `IncomingMessage` fed through `MessageRouter` for resume would get
    misclassified, because the router classifies by text and commands and a submission
    has neither.
- Other shapes considered, and why this one: an AGPL hosted form editor (Formbricks,
  OpnForm, HeyForm, Typebot) was considered for the schema editor and rejected because
  the repository is Apache-2.0 and AGPL components don't fit; MIT-licensed
  `@bpmn-io/form-js` and `@formio/js` remain candidates. A synthetic `IncomingMessage`
  through `MessageRouter` was considered for session resume and rejected for the
  classification reason above; a dedicated `FlowEngine` entry point was chosen instead.

## Scope and non-goals

- In scope: the `Forms` domain and storage, the `form` flow node, the external-event
  resume path, the signed link token, dual (client+server) validation against one schema,
  multilingual label/value handling, the schema editor screen on the kit, and the timeout sweeper
  — the eight decisions below, plus the Core-vs-Solution boundary decision.
- Not doing: per `docs/roadmap.md` § Out of scope, this feature as a whole sits outside
  the current product roadmap's priority ("no anchor in the brief; it neither tests
  extensibility nor shortens the first-contact path") — it is not scheduled ahead of the
  roadmap's numbered steps and does not block the release track. Ready-made form
  templates and mapping answers into Solution-owned data (`hr.*`, `crm.*`) are left to
  Solutions built on top of this spec, not built here.

## Decisions

- **Core, not a Solution.** A form is a new flow-engine node type, a new session-resume
  path driven by an external event, and a bespoke builder override; all three are only
  available inside Core today — a Solution registers an `ActionHandler` for a `call`
  node, not new node types, and Solution Vue components need the M7 publish contract,
  which does not exist yet. Solutions on top of this spec stay possible: ready-made form
  templates and mapping answers into Solution-owned data (`hr.*`, `crm.*`).
- **Storage.** A `Forms` domain in the tenant schema: table `forms` (ULID, `schema` JSON,
  `version`, `assistant_id`). A session snapshots `form_version` when the link is sent,
  the same way it snapshots `flow_version`. One form is reused across different flows.
- **The `form` node.** Its handler sends a message with a button: `web_app` for Telegram,
  a plain link to a hosted page for WhatsApp and other channels. The session moves to
  `waiting_input` but waits for an external event, not text. Answers are saved to a
  `json`-typed variable via `save_to`, with an optional field → `contact.*` mapping
  through `ContactWriterInterface`. Handles: `submitted`, `timeout`.
- **Session resume.** A separate `FlowEngine` entry point for submissions — rejected: a
  synthetic `IncomingMessage` routed through `MessageRouter`, because the router
  classifies by text and commands and a form submission has neither. Uses the same
  `(tenant, contact, assistant)` lock as inbound messages. The submission id is the
  idempotency marker, so a repeat submission does not advance the session twice.
- **Link token.** Signed, bound to `(tenant, session, node, form_version)`, with a TTL.
  For Telegram, additionally verified against initData HMAC and a check that the
  Telegram id matches the session's contact. For the hosted page the token is the only
  protection, so it is single-use.
- **Validation.** One schema, two checks: the renderer validates on the client, Laravel
  rules validate on the server from the same schema. The server response carries
  per-field errors, which the client displays. The server-side check is mandatory; the
  client-side one is a convenience.
- **Multilingual content.** Label, placeholder and error text go through the content
  translator chain as flow content; `value` on a select/checkbox is language-agnostic,
  the same as a button's value.
- **Schema editor.** The schema editor lives in an Inertia/Vue screen on the kit (changed
  2026-10-07: was a Filament `FormResource`, superseded because Core's operator UI leaves Filament
  and no new screen is built on it — `ui-foundation`); the builder
  only lets an author pick an existing form in the node's config. An existing
  open-source editor is embedded rather than hand-built; the dependency needs separate
  agreement and a license filter — rejected: AGPL editors (Formbricks, OpnForm, HeyForm,
  Typebot), because the repository is Apache-2.0. MIT candidates: `@bpmn-io/form-js`
  (editor + viewer, JSON schema, framework-agnostic), `@formio/js` (builder + renderer,
  has a Vue wrapper); SurveyJS is a partial fit (renderer is MIT, Creator is commercial).
- **Timeout and abandoned forms.** A form not completed within the token's TTL moves the
  session down the `timeout` handle via a sweeper, modeled on `SubflowTimeoutSweeper`.

## Open questions

- Schema versioning when links are already sent out: does an old link open the schema
  snapshot from when it was sent, or the form's current version? — decides whether
  `form_version` on the session is authoritative for rendering or only for interpreting
  the answer shape.
- Drafts: should a partially filled form be saved between Mini App openings? — decides
  whether the submission endpoint needs a partial-save path in addition to final submit.
- Does `form` need to work as a trigger — form opened outside a flow, submission starts
  the flow — by analogy with `emit_event`? — decides whether the resume path in Phase 2
  needs to also support starting a new session, not only resuming an existing one.

## Assumptions left untested

- The existing TMA scaffold (`routes/tma.php`, `TmaFormRenderer`, the
  `TmaFormController`/`TmaAuthMiddleware` stubs, `InputExpectedType`, `MediaIngestor`,
  `ContactWriterInterface`) is a sufficient base for Phase 1/2 rather than something that
  needs replacing — taken at normal depth from the source's own description of "what
  already exists in the code"; would be tested by reading those files in detail once
  Phase 1 starts.
- `@bpmn-io/form-js` and `@formio/js` actually fit the embedding need (JSON schema
  compatibility inside a Vue screen on the kit, a usable Vue integration) — taken at normal depth as a
  named candidate list, not a verified fit; would be tested with a short embedding spike
  before committing to one in Phase 1.
- `docs/roadmap.md` § Out of scope reflects the current, still-valid product priority —
  taken at face value from that document as of this migration; would be tested by
  checking whether that file's "Out of scope" entry for forms has changed before this
  spec's phases are scheduled.
