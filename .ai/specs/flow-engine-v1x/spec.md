# Flow engine V1.x

Depth: easy — this collects deferred work the Flow Engine V1 design already named and postponed; it
is a migration of existing intent into Jig, not a fresh idea, and it was not stress-tested with the
owner item by item.

## Idea

The V1 node set shipped with a list of things deliberately left for "V1.x or later". On 2026-10-05
the owner asked to move all old documentation into Jig and to come out with a complete roadmap; the
deferred items were scattered over `docs/reference/specs/flow-engine/09-out-of-scope-and-open-questions.md`,
the loop node spec, the call and subflow node specs, ADR-08, ADR-09 and ADR-11. They are gathered
here so that none of them lives only in a document nobody plans from.

## Goal and problem

- Who is worse off without this, and how: a flow author hits limits the engine documents as
  "later" — a loop that silently burns the shared iteration budget, a `call` that cannot retry, a
  subflow that cannot pass parameters — and nobody can tell which limits are planned and which are
  permanent.
- What is true when the work is done: every item below is either built or explicitly dropped, and
  the node specs under `docs/reference/specs/flow-engine/nodes/` no longer carry "not built" lists.

## Stress test

- Hidden assumptions — "this holds only if …": every item stays additive, as the V1 design
  promised ("each is a separate feature, none needs a breaking change to V1"). It holds only while a
  node contract change ships as a new handler version, never as an edit to v1.
- The main trade-off: authoring safety (more publish-time checks) against authoring friction — a
  warning that fires on valid flows teaches authors to ignore warnings.
- The weakest point: there is no demand signal. These were deferred because no rollout needed them;
  the order below is a guess until a client rollout asks for one.
- Failure modes — cause, what breaks, the signal that shows it: adding `call` retries without the
  outbound idempotency key (`attempt_number`, Redis dedup) duplicates side effects at the remote
  system; the signal is a duplicate record created by one session.
- Other shapes considered, and why this one: one spec per node — rejected, most items are one task
  each and a spec per item would hide the order between them.

## Scope and non-goals

- In scope:
  - loop: iterator-vs-session variable conflict check (8.3b), "while condition never changes"
    warning (8.5), static numeric `count_source` check (8.7), a loop-aware iteration budget instead
    of the shared `flow.execution.max_iterations = 100`, `{{x.length}}` in templates, a `max_size`
    editor in the builder;
  - call: retry with backoff, `strict_mapping`, a generic `success_statuses`, `attempt_number` plus
    Redis outbound dedup with a TTL at least the queue retention plus grace (the ADR Idempotency
    Strategy the routing ADR left as TBD);
  - subflow: `input_mapping` / `output_mapping` and a pinned target version;
  - emit_event: an explicit `assistant_filter` on event triggers;
  - validation: a design-time leaf-vs-group variable path check (today only the publish-time type
    conflict check and the runtime `StructuralPathConflictException` exist);
  - expression engine per tenant (ADR-08): a tenant setting, boot validation and a snapshot at save —
    today the engine comes from `flow_definitions.expression_engine` with `template` as the only one;
  - routing (ADR-09 V1.x): commands registered by a Solution, more command action types;
  - node capabilities: `auth_request` by phone/SMS/email (only `Basic` exists); `notify` staff
    identity on a messenger and staff targeting by tag.
- Not doing: `rag_query` storage (spec `rag-knowledge-bases`); a prompt node, NLP date parsing,
  custom input validators and custom expression functions — named in the V1 out-of-scope list, kept
  out until a rollout asks; cross-assistant subflows (V2, needs its own ADR).

## Decisions

- Items stay additive: a contract change is a new handler version — rejected: editing a v1 handler's
  config shape, because stored nodes pin `type@version` (domain rules, flow).

## Open questions

- Which item a real rollout needs first — decides the order of phases 2–4.
- Whether `call` retries live in the handler or in the transport — decides where the idempotency
  key is minted.

## Assumptions left untested

- That none of these needs a breaking change — taken from the V1 design; each task re-checks it.
