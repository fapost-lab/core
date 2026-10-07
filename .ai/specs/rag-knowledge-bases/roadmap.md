# Roadmap — RAG Knowledge Bases

Destination: a flow can run `rag_query` against a real knowledge base and get back a structured,
adapter-backed answer, with `knowledge_base_id` validated against real data at build time — instead
of the node always failing at the runtime guard.

## Phase 1 — Decide the provider and storage

Goal: the embeddings provider and vector storage are chosen. Done when: a written decision names the
provider/storage (pgvector or an external service) and its rationale, giving every later item a
fixed target to build against.

- [ ] Decide the embeddings provider and vector storage (pgvector vs. an external service) — blocks
      every other item in this roadmap

## Phase 2 — Make knowledge bases a real, configurable resource

Goal: a knowledge base can be created and managed in the console. Done when: staff can create a
`knowledge_base` record through the console and it persists with the fields the chosen provider needs.

- [ ] Add the `knowledge_bases` table and migration, shaped for the chosen provider (after: provider
      decision — the schema depends on what the chosen storage needs)
- [ ] Add a console screen to create and manage knowledge bases, built on the kit (after:
      `knowledge_bases` table — needs the model and table to manage; after: ui-foundation phase 2 —
      the screen is built on the kit)

## Phase 3 — Wire a real adapter end to end

Goal: `rag_query` answers from a real knowledge base instead of failing at the runtime guard. Done
when: a flow with a `rag_query` node pointed at a real knowledge base returns a
`StructuredRagResult` with `found=true` for a query that matches indexed content.

- [ ] Add the `StructuredRagResult` DTO (found, confidence, answer, intent, metadata) (after:
      provider decision — the fields must match what the chosen provider can actually return)
- [ ] Build the first RAG adapter for the chosen provider and register it in `RagAdapterRegistry`
      (after: `StructuredRagResult` DTO — the adapter returns this shape)
- [ ] Validate `knowledge_base_id` against real data in `ValidateFlowService`, replacing the current
      runtime-only guard (after: `knowledge_bases` table — needs real rows to validate against)

## Waves

1. Decide the embeddings provider and vector storage (pgvector vs. an external service)
2. Add the `knowledge_bases` table and migration, shaped for the chosen provider; Add the `StructuredRagResult` DTO (found, confidence, answer, intent, metadata)
3. Add a console screen to create and manage knowledge bases; Build the first RAG adapter for the chosen provider and register it in `RagAdapterRegistry`
4. Validate `knowledge_base_id` against real data in `ValidateFlowService`, replacing the current runtime-only guard

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
