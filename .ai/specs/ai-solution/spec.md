# AI Solution — every AI capability of FaPost in one Solution

Depth: deep — it ends in changes to Core's and Foundation's extension contracts and to the published
extension docs, it sets how FaPost sells AI, and three circles were needed: the shape, the interaction
with other Solutions, and the sale.

## Idea

The owner, translated from Russian (2026-10-16, task `ai-solution-strategy-idea`): "Keep Core minimal.
Everything about AI — first of all RAG and what comes with it (embeddings, vector storage, indexing) —
moves out into a Solution, installed from the marketplace by those who need it. The MCP server is not
part of this. I am not yet sure what such a Solution should contain at all — that is what has to be
worked out."

Said during the conversation (2026-11-12/13):

- "The idea was exactly one Solution holding all the nodes and everything AI needs. I did not mean
  every block to be a Solution of its own."
- On Core or Solution: "On one side, AI is in every product now, and free, but that is not at all what
  we offer. On the other, a Solution can be sold, and Core is free." — then: "We keep it a Solution."
- "A Solution must create nodes and their handlers — that was the idea from the start. Using `call` is
  nice, but the UI suffers badly; I already have a similar solution and wanted to get away from it."
- "The marketplace sells only to self-hosted. In SaaS the operator connects Solutions and sets up plans
  for them." And: "Self-hosted — only their own keys. We sold them the Solution, that is enough."

## Goal and problem

- Who is worse off without this, and how: a tenant who wants a bot that answers from their own
  documents, classifies requests or pulls fields out of a message — today the `rag_query` node sits in
  the palette and always fails, and nothing else exists; an operator who handles every message by hand;
  and the owner, whose Core carries half-built RAG plumbing in about eight places of the Flow domain
  while having nothing AI to sell.
- What is true when the work is done:
  - Core holds no AI-specific code — only seams every Solution uses;
  - one verified Solution gives a working answer from a tenant's knowledge base, LLM actions in flows,
    voice-to-text, operator assist and an agent;
  - in SaaS a tenant's token spend is visible and limited through the plan's quotas;
  - a self-hosted installation buys the Solution once and runs it on its own keys or a local model.

## Stress test

- Hidden assumptions — "this holds only if …":
  - A Solution can bring its own nodes and state namespace. False today: `SolutionCoreRegistrar`
    refuses `registerNodeHandler`, `registerDataAccessor` and `registerMigrations`; the Plugin path
    fails at boot because Core never binds `CoreRegistrarInterface`; the published docs say a Solution
    "does not add node types" and that namespaces are "never invented per node". The owner called that
    a mistake against the original design; it is fixed by task `solution-node-types`, outside this spec.
  - Other Solutions can use AI without a contract for every case. Holds only if flows are the bus
    (a Solution's event starts a flow, an AI action runs, a Solution's action takes the result) and
    the few interactive needs share one generic contract.
  - pgvector is usable from every tenant schema. Holds only if the extension sits in a schema on every
    tenant's `search_path` and the hosting Postgres ships it; `CREATE EXTENSION` needs a superuser, so
    it is an installation step, not a tenant migration.
- The main trade-off: selling AI as a Solution (Core stays free and small, AI is revenue) against AI as
  a platform capability (any Solution and Core could call it without depending on another package) —
  chosen: Solution; interaction goes through flows and one contract.
- The weakest point: demand and shape are unknown — the owner is not sure yet what AI the product needs,
  so the phase order is a guess, and the first phase is chosen as the cheapest one that is useful alone.
- Failure modes — cause, what breaks, the signal that shows it (independent failure hunt):
  - Moving `rag_query` out breaks published flows — a stored `rag_query@1` with no handler; signal:
    "unknown node type" after the Core release. Mitigated by the node never having worked; checked on
    data before removal.
  - pgvector per schema — `type vector does not exist` in a tenant migration, an HNSW index per tenant
    schema growing with tenant count, managed Postgres without the extension.
  - Token metering leaks money — concurrent calls pass the quota check together, retries and background
    embedding jobs are counted late or twice; signal: the provider's invoice above the sum of meters.
  - One tenant's indexing or slow LLM calls hold shared workers; one platform key's rate limit shared by
    all tenants; signal: queue wait time and 429s from the provider.
  - Embeddings tied to a model — a changed model gives a different dimension (hard fail) or the same
    dimension in another space (silent garbage); signal: confidence collapses, handoffs jump.
  - Confidence not calibrated — the handoff branch fires almost never or almost always.
  - `extract` writes malformed values into variables — the error shows up nodes later, or in a CRM.
  - The crawler fetches tenant-supplied URLs from platform infrastructure — SSRF into internal networks.
  - Conversation data and keys reach a third-party provider or the logs — security questionnaires stall.
  - Seams shaped by one consumer — the second Solution, or a community AI Solution, collides with names
    or AI-specific assumptions in "generic" APIs.
- Other shapes considered, and why this one: see Decisions.

## Scope and non-goals

- In scope:
  - the Provider module: chat model, embeddings and vector store contracts with drivers; keys; consent;
    PII masking and moderation; token metering;
  - LLM actions in flows: generate, classify, extract;
  - voice-to-text;
  - knowledge bases with sources, indexing, `rag_query`, a sandbox, unanswered questions and evaluation
    sets;
  - operator assist in the inbox: reply suggestion, conversation summary, translation;
  - one generic AI contract other Solutions' screens can call;
  - an agent node; a builder copilot;
  - the Core seams these need, built generic for every Solution.
- Not doing:
  - the MCP server — it stays in Core (spec `mcp-server`) and is not part of this Solution;
  - FaPost tokens for self-hosted installations;
  - marketplace sales in SaaS;
  - editions inside the Solution's licence in v1.

## Decisions

- One Solution with internal modules — Provider, Nodes, Knowledge, Assist, Agent, later Insights — all
  enabled together with the Solution; a module that a tenant does not use costs nothing heavy — rejected:
  a Solution per block, because the owner did not mean it and modules need to interact freely.
- AI lives in a Solution, not in Core — rejected: everything in Core, because the knowledge base is
  heavy and optional and Core is free; and a light AI layer in Core with heavy modules in the Solution,
  because the modules may all go unused, belong together, and the Solution is what is sold.
- Contracts in the LangChain manner — chat model, embeddings, vector store — each with drivers; the
  default implementation on the official Laravel AI SDK (`laravel/ai`), pgvector the default vector
  store, a local model (Ollama or an OpenAI-compatible endpoint) supported from the first phase. The
  owner sees these contracts as the base for later n8n-style visual automation — rejected: an own HTTP
  client, because drivers, streaming, structured output and embeddings are not this project's
  competence and the SDK will be maintained by the community. The dependency lives in the Solution's
  package, not in Core.
- LLM steps reach flows as Solution actions with a config schema and named outcomes on `call@2`: the
  palette shows "AI → Classify" as its own item with its own form and branches (`classified`,
  `low_confidence`, `invalid`, `error`); results go through mapping into ordinary `flow.*` variables —
  rejected: a bare `call` with hand-typed parameters, because of the UI (the owner is moving away from
  exactly that). The same schema serves every Solution's actions.
- A Solution registers its own node types, handlers and state namespace (task `solution-node-types`);
  the agent and anything else that does not fit a call uses it.
- `rag_query`, `RagAdapterRegistry`, the hardcoded `rag.*` writers and the RAG contracts leave Core once
  the Solution carries `rag_query`; `rag` in Foundation's `StateNamespace` is deprecated and removed in
  Foundation's next major, as the stability policy requires — rejected: keeping the contract in Core
  (an AI concept in Core forever), deleting it before a replacement exists (breaks the palette promise).
- Interaction with other Solutions: background work goes through flows as the bus — a Solution's event,
  an AI action, a Solution's action; interactive work on another Solution's screen goes through one
  generic AI contract in Foundation (generate, classify, extract); when the AI Solution is not active
  for the tenant, the answer is "unavailable" and the caller hides the feature; one provider of that
  contract per tenant, chosen in settings — rejected: direct contracts per use case, because "you
  cannot foresee them all".
- The AI Solution reads other Solutions' data (Feedback answers, Forms submissions) as knowledge-base
  sources through data accessors, so those Solutions need not know about AI.
- Keys: in SaaS the operator sets the platform key in installation settings and the tenant may use its
  own key instead, stored encrypted in runtime settings; a tenant's own key is logged for statistics
  but not counted against the quota. Self-hosted runs only on its own keys or a local model — rejected:
  FaPost tokens for self-hosted, because "we sold them the Solution, that is enough".
- Quotas in SaaS: the plan grants the Solution (the existing entitlement seam), and the Solution
  registers a per-period limit key `ai.tokens`; an exhausted quota sends a node to its `error` branch
  with `quota_exceeded`, and operator assist shows "unavailable" without breaking the inbox.
- Metering: every call — actions, indexing, assist — passes one metering point in the Provider; the
  quota is checked before the call and the actual spend recorded after it; the record is idempotent by
  a deterministic key, so a retried call is not charged twice; overrun is bounded by one call per
  concurrent flow — rejected: pre-reserving tokens, which costs much and buys little.
- Data: an external provider requires the tenant's consent at activation, and the screen names the
  provider and its region; PII is masked before sending; keys and prompt texts never reach logs or flow
  state; with a local model nothing leaves the installation, and the screen says so.
- A slow LLM call does not hold a flow worker: the node waits and a job carries the call, resuming the
  flow on its result, on the pattern `delay` already uses; indexing runs on the Solution's own queue;
  each key has its own rate limiter.
- Each knowledge base pins its embedding model and dimension; changing the model is an explicit
  re-index with a cost estimate.
- `extract` declares its fields with types; an invalid answer takes the `invalid` branch and writes
  nothing.
- The crawler goes out only through Core's egress guard.
- Node types and actions from packages carry the package's prefix, so a community AI Solution cannot
  collide with this one.
- A Solution that is deactivated, or not in the tenant's SaaS plan, makes its nodes take `error` with
  `solution_inactive`; the builder shows them unavailable and keeps their config; knowledge-base data is
  kept. An expired self-hosted licence stops only new versions — the installed one keeps working, as
  the marketplace decided.
- Sale: in SaaS the operator connects the Solution and puts it into plans, earning on the plan and the
  token markup; the marketplace sells only to self-hosted, a paid licence with an update period, no
  editions in v1 — rejected: per-tenant marketplace subscriptions in SaaS, because the operator's plans
  are simpler.
- A self-hosted installation without the Solution sees one greyed "AI — install the Solution" group in
  the palette, and only when the marketplace is reachable from it.
- The vector extension is an installation step: Core's Postgres image ships pgvector, the operator
  enables it once, and the Solution refuses activation with a clear reason when it is missing.
- Phases ship one by one, so no epic.
- Consequences for other specs, each applied in its own change:
  - `rag-knowledge-bases` is absorbed by this spec and removed;
  - `flow-engine-v1x`: the "prompt node, kept out" line and the `rag_query` storage pointer now point
    here; `call@2` with action schemas is a candidate item there;
  - `marketplace`: sells only to self-hosted — drop "SaaS — a subscription per tenant", drop the open
    question "how a SaaS tenant's purchase becomes an activation" (the shell's), revisit "SaaS billing
    and marketplace payments are one process", add SaaS sales to "Not doing"; this Solution is the first
    paid product that tests the renewal assumption.

## Open questions

- Voice-to-text: an action inside the flow (simple) or preprocessing of inbound messages before the
  flow (needs a Core seam) — decides what phase 1 asks of Core.
- Whether `call@2` with action schemas is an item of `flow-engine-v1x` or a task of its own — decides
  the order of Core work before phase 1.
- On what terms a SaaS operator installs third-party products — a per-installation licence is unfair to
  a publisher when one SaaS installation serves thousands of tenants; belongs to the marketplace spec,
  needed before third-party publishers arrive.

## Set aside

- An "action with schema" improvement of `call` for its own sake (forms and branches for every
  Solution's actions) — taken here as the means for LLM actions; anything beyond what this spec needs
  belongs to `flow-engine-v1x`.
- Editions inside one licence (cheaper base modules, dearer knowledge base, assist, agent) — set aside
  because the marketplace has no feature-scoped licences and nobody knows yet what sells.
- Images and documents into `extract` (receipts, IDs) — set aside until a customer asks.
- unexplored: the agent from the inside — which tools it may get, how to bound what it calls, what the
  author sees.
- unexplored: SaaS economics — the default model, the markup, a trial allowance.
- unexplored: the builder copilot — what it generates and how it avoids damaging a flow.
- unexplored: analytics — topics, sentiment, the share closed by the bot.

## Assumptions left untested

- The Laravel AI SDK has an Ollama or OpenAI-compatible driver — taken at deep; reading its provider
  list before phase 1 tests it.
- pgvector works from every tenant schema through `search_path` and on managed Postgres — taken at deep;
  a tenant migration on a managed instance tests it.
- No published flow uses `rag_query` and no template reads `rag.*` — taken at deep; a query across
  tenant schemas before the removal tests it.
- The flow engine can park a node until a job's result, the way `delay` and operator timeouts resume a
  session — taken at deep; the `call@2` design tests it.
- The order of phases matches what customers will want — taken at deep; the first rollouts test it.
