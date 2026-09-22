# Roadmap — Forms / Data Collection

Destination: a contact fills a multi-field questionnaire on one form screen (Telegram
Mini App or hosted page), validated once against a single schema on both client and
server, and the flow session resumes on submission through a dedicated path — with
answers mapped into the contact record, the transcript and an export, and the form
itself authored in Filament without touching code.

## Phase 1 — Foundation

Goal: the domain and security primitives exist. Done when: a form can be created and
stored with a schema, and a submission payload can be validated against that schema by
server-side rules, with a signed token that round-trips through verification.

- [ ] Real Telegram initData HMAC verification in `TmaAuthMiddleware`, replacing the
  "401 outside local" stub
- [ ] `Forms` domain: `forms` migration (ULID, `schema` JSON, `version`,
  `assistant_id`), model and repository
- [ ] Signed link token bound to `(tenant, session, node, form_version)` with a TTL;
  single-use for the hosted page
- [ ] Server-side validation of submitted answers against the form's schema (schema →
  Laravel rules), returning per-field errors
- [ ] Open-source schema editor selected and license-checked — MIT only
  (`@bpmn-io/form-js`, `@formio/js`); AGPL editors (Formbricks, OpnForm, HeyForm,
  Typebot) excluded

## Phase 2 — Runtime

Goal: a flow can send a form and resume on submission end-to-end, across channels. Done
when: a contact opens the form via the Telegram `web_app` button or a hosted-page link,
submits it, and the flow session resumes exactly once past the `form` node with the
answers saved.

- [ ] `FormNodeHandler` v1: `web_app` button for Telegram, plain link for other
  channels; saves answers to a `json`-typed `save_to` variable; handles `submitted` /
  `timeout`
- [ ] Submission endpoint and a separate resume path in `FlowEngine` (not through
  `MessageRouter`), using the same `(tenant, contact, assistant)` lock as inbound
  messages (after: `FormNodeHandler` v1 — there is nothing to resume from before a node
  sends the form)
- [ ] Idempotency by submission id, so a repeat submission does not advance the session
  twice (after: submission endpoint and resume path — idempotency guards that exact
  code path)
- [ ] Timeout sweeper modeled on `SubflowTimeoutSweeper`, moving the session down the
  `timeout` handle once the token's TTL expires
- [ ] `TmaFormRenderer` renders the real schema instead of the hardcoded stub, and
  checks that the initData Telegram id matches the session's contact
- [ ] Hosted form page for non-Telegram channels (WhatsApp)
- [ ] Label / placeholder / error text through the content translator chain; `value`
  stays language-agnostic

## Phase 3 — Authoring

Goal: an operator can build and wire a form without touching code. Done when: a form
created in Filament can be picked on a `form` node in the builder, and publishing a flow
rejects a `form` node that points at a form outside its own assistant.

- [ ] Filament `FormResource` with the embedded schema editor, plus `FormPolicy` and its
  `Permission`
- [ ] Builder `FormConfig.vue` override (form picker, `save_to`, field mapping), palette
  entry and `NodeIcon` (after: Filament `FormResource` — there is nothing to pick in the
  builder before a form can be authored)
- [ ] Publish-time validation that a `form` node references an existing form belonging
  to the same assistant (after: builder `FormConfig.vue` override — the check applies to
  the config that override produces)

## Phase 4 — Data

Goal: a submitted form becomes part of the contact record and the operator-visible
history, not just a flow variable. Done when: a submitted answer updates the mapped
contact field, appears in the transcript, and can be exported.

- [ ] Field → `contact.*` mapping applied through `ContactWriterInterface`
- [ ] File answers ingested through `MediaIngestor`
- [ ] Submission recorded in the transcript as a system message
- [ ] Export of form answers

## Waves

1. Phase 1 — foundation (`Forms` domain, link token, initData HMAC, server-side
   validation, editor license decision): no runtime dependency on the other phases.
2. Phase 2 (runtime: node handler, resume path, sweeper, renderer, hosted page) and
   Phase 3 (authoring: Filament resource, builder override, publish validation) — both
   depend only on the Phase 1 domain and token, not on each other; runtime touches
   `FlowEngine`/channel senders while authoring touches Filament/builder, so the two can
   proceed in parallel once Phase 1 lands.
3. Phase 4 — data integration, which needs the submission path and saved answers that
   only exist once Phase 2's runtime is in place.

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
-->
