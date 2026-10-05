# RAG Knowledge Bases

Depth: normal — the runtime shape is already built and stable, so this is not a from-scratch design;
but the feature is blocked on one real decision (embeddings provider and vector storage) that the
source itself flags as high-cost-if-wrong, which is more than a trivial "just wire it up" pass.

## Idea

Knowledge base integration: search over documents from within a flow.

## Goal and problem

- Who is worse off without this, and how: any flow that needs to answer from a knowledge base
  cannot — the `rag_query` node already exists in the palette but always fails on a runtime guard,
  so a flow author who reaches for it today gets a node that is broken by design.
- What is true when the work is done: a flow can run `rag_query` against a real `knowledge_base`,
  get back a `StructuredRagResult` (found/confidence/answer/intent/metadata), and
  `ValidateFlowService` checks `knowledge_base_id` against real data at build time instead of only
  guarding at runtime.

## Stress test

- Hidden assumptions — "this holds only if …": this holds only if the chosen embeddings/vector
  storage approach fits inside the platform's existing tenant-scoped multi-tenancy model without
  becoming a new class of deployment dependency the team can't operate.
- The main trade-off: a self-hosted pgvector store (more operational ownership, tighter integration,
  no per-query vendor cost) versus an external vector service (less to operate, faster to ship, an
  ongoing external dependency and cost).
- The weakest point: the provider/storage decision has already sat unresolved through one whole
  re-sequencing (moved from right after M2 to the backlog, after M12) — it is not a technical gap,
  it is an unmade decision, and nothing else in this spec can start ahead of it.
- Failure modes — cause, what breaks, the signal that shows it: shipping around the decision instead
  of making it leaves `rag_query` permanently broken in the palette — which is already the current
  state; the signal is any flow containing a `rag_query` node failing at the runtime guard,
  indefinitely.
- Other shapes considered, and why this one: building the runtime first and storage later (the
  current state) was a deliberate sequencing choice — the runtime pieces (`RagAdapterRegistry`,
  `RagQueryNodeHandler`, validation) shipped ahead of storage because they were low-risk and
  reusable regardless of which provider gets picked, while the provider/storage choice was judged
  worth waiting on rather than rushing.

## Scope and non-goals

- In scope: the provider/storage decision; the `knowledge_bases` table, its migration and a Filament
  resource; the `StructuredRagResult` DTO; the first real adapter; `knowledge_base_id` validation
  against real data in `ValidateFlowService`.
- Not doing (already done, not part of this work): `RagAdapterRegistry`; `RagQueryNodeHandler`
  (type `rag_query`, writes `rag.*` into state); the RAG config-shape and runtime-guard validation
  already present in `ValidateFlowService`. The node's contract as built is recorded below.

## Contract as built (2026-10)

- Principle: the flow engine is deterministic and an LLM is not, so `rag_query` never puts raw
  provider output into flow state. The adapter normalises the provider's answer into
  `StructuredRagResult` (found, confidence `low|medium|high`, answer, intent?, metadata); the
  normaliser belongs to the adapter, not to the node. A downstream `branch` reads `rag.found` /
  `rag.confidence`, and `send_message` uses `rag.answer`.
- `RagAdapterInterface` lives in `fapost/foundation`; adapters register in `RagAdapterRegistry`.
- Node `rag_query` v1 (`app/Domains/Flow/Handlers/RagQueryNodeHandler.php`): config requires
  `knowledge_base_id` (literal, not an expression), `query` (expression) and `provider` (literal
  registry key); optional `options` (adapter-specific). Handles `success` (`found=true`),
  `not_found`, `error`. Results are written as `rag.found|confidence|answer|intent|metadata` through
  `stateChanges`; `rag.*` is writable by `rag_query` only (`SystemStateNamespacePolicy`).
- Interim shape: because no `knowledge_bases` table exists, the provider is chosen by the literal
  `provider` key. Once knowledge bases ship, the handler takes `knowledge_base_id` only and resolves
  the provider from the row; `ValidateFlowService` then checks that the base exists for the tenant
  and that `options` suit its provider.
- Earlier storage sketch (not decided): `knowledge_bases` (id, name, provider, config JSON) and
  `knowledge_documents` (title, content, embedding_id, knowledge_base_id, meta).

## Decisions

- The feature was moved from right after M2 into the backlog, after M12 — rejected: shipping on the
  original schedule with runtime-only support, because the node would stay non-functional in the
  palette regardless of timing, and the provider choice was judged to carry more downside risk than
  the upside of shipping the runtime sooner.
- `rag_query` stays visible in the palette and fails at the runtime guard until storage exists —
  rejected: hiding or removing the node from the palette in the meantime, because this is documented,
  deliberate debt rather than an accidental gap.

## Open questions

- Which embeddings provider and vector storage: self-hosted pgvector, or an external service? —
  blocks the `knowledge_bases` schema, the first adapter, and every other item in this spec; this is
  the decisional blocker the source calls out by name.

## Assumptions left untested

- That the `StructuredRagResult` shape (found, confidence, answer, intent, metadata) — already fixed
  by the node contract above — is sufficient for a real adapter's response — taken at idea depth from
  that contract; would be tested once a real provider is
  wired in and its actual response shape is mapped onto it.
- That sequencing this after M12 (MCP Server) is still right — taken at idea depth as roadmap
  ordering; would be tested by revisiting the sequencing once M12 itself is scheduled.
