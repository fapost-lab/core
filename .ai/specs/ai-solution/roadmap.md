# Roadmap — AI Solution

Destination: a tenant installs one AI Solution and gets LLM actions, voice-to-text, a knowledge base,
operator assist and an agent, metered in SaaS and on its own keys in self-hosted, while Core holds no
AI-specific code.

## Phase 0 — Seams in Core every Solution stands on

Goal: a Solution can do what the AI Solution needs without an AI-specific line in Core. Done when: a
test Solution shows an action in the palette with its own form and branches, keeps a tenant table, runs
a job on its own queue, registers a quota key and exposes a data accessor.

- [ ] `call@2` with action schemas — a Solution action appears in the palette as its own item with a form, named outcomes and a wait while a job runs
- [ ] Solution tenant migrations and queue — a Solution keeps tenant tables and runs background jobs on a queue of its own
- [ ] Solution quota keys — a Solution registers a per-period limit key the SaaS shell can meter
- [ ] Solution data accessors — a Solution exposes its data under `module.<namespace>.*` for flows and for other Solutions

## Phase 1 — Provider and LLM actions

Goal: a flow can classify, extract and generate, and a voice message becomes text, on a platform key or
the tenant's own. Done when: a flow classifies an inbound message into a branch on a tenant's own key
and, in SaaS, on the platform key with the spend visible in the tenant's quota.

- [ ] Provider module — contracts with drivers on `laravel/ai` and a local model, the keys screen, consent, PII masking and moderation, one idempotent metering point (after: Solution quota keys — the spend lands in `ai.tokens`)
- [ ] LLM actions — generate, classify, extract with typed fields and an `invalid` branch (after: `call@2` with action schemas — they are actions with schema; Provider module — they call the model through it)
- [ ] Voice-to-text — a voice message becomes text a flow can use (after: Provider module — it calls the model through it)

## Phase 2 — Knowledge base

Goal: a bot answers from the tenant's own material and hands off when unsure. Done when: a tenant loads
a document, asks in the sandbox, sees the found chunks and the answer, and a flow routes a low-confidence
answer to an operator.

- [ ] Knowledge bases — sources (files, URLs through the egress guard, Q&A, other Solutions' data), chunking, embeddings pinned per base, indexing on the Solution's queue, pgvector (after: Solution tenant migrations and queue — tables and indexing jobs; Solution data accessors — other Solutions as sources; Provider module — embeddings)
- [ ] `rag_query` in the Solution — retrieval with confidence branches, the sandbox and unanswered questions (after: Knowledge bases — it reads them)
- [ ] Evaluation sets — expected answers run before publishing and after a model change (after: `rag_query` in the Solution — it is what they check)
- [ ] RAG out of Core — remove `RagQueryNodeHandler`, `RagAdapterRegistry` and the hardcoded `rag.*` writers, deprecate `rag` in Foundation (after: `rag_query` in the Solution — stored nodes need somewhere to go)

## Phase 3 — Operator assist

Goal: an operator answers faster and in the client's language. Done when: on an open conversation the
operator gets a suggested reply and a summary, and reads an inbound message in their own language.

- [ ] Reply suggestion and summary — in the inbox, on the conversation (after: Provider module — it calls the model through it)
- [ ] Translation — inbound for the operator, outbound into the client's language (after: Provider module — it calls the model through it)
- [ ] Shared AI contract — one Foundation contract other Solutions' screens call, one provider per tenant (after: Provider module — it implements the contract)

## Phase 4 — Agent and copilot

Goal: a flow can hand a goal to an agent, and an author can start a flow from a description. Done when:
an agent node answers a question by calling a Solution action, and the builder drafts a flow from text.

- [ ] Agent node — an LLM with tools (actions, HTTP, subflows, external MCP servers) and memory (after: `solution-node-types` — the agent is a real node type)
- [ ] Builder copilot — a flow drafted from a description (after: LLM actions — it needs the Provider and a node set worth composing)
- [ ] fog: analytics — topics, sentiment, the share closed by the bot; what to count is not known yet
- [ ] fog: images and documents into extract — waits for a customer

## Waves

1. `call@2` with action schemas; Solution tenant migrations and queue; Solution quota keys; Solution data accessors
2. Provider module
3. LLM actions; Voice-to-text; Knowledge bases; Reply suggestion and summary; Translation; Shared AI contract
4. `rag_query` in the Solution; Builder copilot; Agent node
5. Evaluation sets; RAG out of Core

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
