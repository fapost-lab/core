# MCP Server

Depth: normal — this migrates an already-accepted plan (`docs/platform/ROADMAP.md` §Milestone 12,
`docs/platform/TASKS.md` §MCP Server) into Jig format; the architecture decisions and phases are
transcribed, not redesigned.

## Idea

FaPost as a surface for AI agents: an external agent (Claude, ChatGPT, a tenant's own agent) reads
and operates the platform through the Model Context Protocol.

## Goal and problem

- Who is worse off without this, and how: a tenant or integrator who wants an external AI agent to
  work with FaPost has no path to it today. The platform already holds everything such an agent
  would want — assistants, flow definitions, contacts and segments, conversation transcripts,
  broadcasts — but all of it is reachable only by a human clicking through Filament.
- What is true when the work is done: an external agent can read, and — behind a separately gated
  scope — operate the platform through MCP tools, resources and prompts: tenant-scoped, permission-
  checked and audited, without standing up a bespoke REST API and without a human working the admin
  panel on the agent's behalf.
- Sequencing carried over from the source, as context rather than a re-derived decision: this milestone
  sits after Milestone 2 and grows alongside M3/M4, since conversation transcripts and broadcasts are
  the main material several of its tools read.

## Stress test

- Hidden assumptions — "this holds only if …":
  - tenant resolution from a bearer token is reliable and fail-fast is enforced on every path — no
    code path may fall back to a "default tenant" (AGENTS.md, Tenant-Aware Execution).
  - the existing `Permission` enum and Policy classes are granular enough to express MCP scopes
    without a parallel authorization model.
  - the dependency choice (`laravel/mcp` vs a custom JSON-RPC implementation) and the token model
    (no Sanctum is installed today) get settled by ADR before Phase 1 implementation starts — both
    are explicitly left open in the source.
- The main trade-off: turning Core into a programmable surface for external agents (no separate REST
  API, no manual Filament work) against a materially larger attack surface — a bearer token that, if
  mis-scoped or mis-resolved, can reach across tenants or trigger writes (sends, publishes) that a
  human operator would otherwise gate by hand.
- The weakest point: two foundational decisions are still open at the source (the `laravel/mcp`
  dependency and the token model), and the project's own rules require agreement before adding a
  dependency (AGENTS.md, Laravel And PHP Rules) — nothing in Phase 1 can be built to spec until both
  are resolved in the ADR that Phase 1 itself calls for.
- Failure modes — cause, what breaks, the signal that shows it:
  - a tool resolves tenant context incorrectly or falls back to a default → cross-tenant data leak;
    mitigated by fail-fast tenant resolution through `TenantSwitcher::runForTenant()` and a rule that
    no tool talks to the landlord connection directly.
  - a write tool ships without an idempotency marker → a retried call double-applies (duplicate send,
    duplicate publish); mitigated by requiring an idempotency marker on every write path from Phase 3.
  - a destructive tool ships without scope gating → unintended delete/publish/send; mitigated by v1
    being read-first, with destructive operations behind a separate scope and
    `destructiveHint`/`readOnlyHint` annotations.
  - no audit trail or rate limit → unaccountable or abusive tool use; mitigated by `mcp_audit_log` on
    every call and per-token rate limiting.
  - a long-lived worker retains tenant/server state between calls → the next call executes under the
    wrong tenant; mitigated by keeping ingress stateless with only `scoped` bindings (AGENTS.md,
    Long-Lived Worker Safety).
- Other shapes considered, and why this one:
  - the `app/Mcp` base folder that `laravel/mcp` generates by default was considered and rejected in
    favor of a dedicated `app/Domains/Mcp` domain, to keep the Directory Boundaries rule intact.
  - a local/stdio MCP server for tenant-facing use was considered and rejected; stdio stays a
    dev-only tool, tenants are served over Streamable HTTP.
  - a bespoke authorization model for MCP tools was considered and rejected in favor of reusing the
    existing `Permission` enum and Policy/Gate infrastructure.

## Scope and non-goals

- In scope: an MCP server exposing FaPost's existing domains — assistants, flow definitions,
  contacts/segments, conversations, broadcasts, and flow-session/flow-log runtime diagnostics — as
  tools, resources and prompts to external AI agents; tenant-scoped, permission-gated, and audited;
  extensible by Solutions/Plugins through a registry.
- Not doing:
  - MCP **client** — invoking external MCP servers from inside a flow (a node such as `mcp_call`, or
    a new transport for the `call` node type). The source marks this explicitly out of scope for this
    block: it is a separate direction tied to the AI layer and to Milestone 5 (RAG).
  - OAuth 2.1 — left as an open question (below); v1 does not commit to it.
  - MCP Apps (interactive HTML panels rendered in the client) — left as an open question (below); not
    committed for v1, and flagged as a risk of duplicating Filament.
  - A separate REST API alongside MCP — the stated point of this surface is to avoid needing one.
  - Deciding *whether or when* this milestone gets picked up: `docs/roadmap.md` currently lists
    "Model-integration server surface" under **Out of scope** for the product roadmap ("no section
    of the brief justifies it; it closes none of the three positioning pillars"). This spec only
    preserves the accepted engineering plan from `docs/platform/ROADMAP.md`/`TASKS.md`; it does not
    reopen or override that product-priority call.

## Decisions

- Code boundaries: new domain `app/Domains/Mcp` (`Server`, `Tools/{Domain}`, `Resources`, `Prompts`,
  `Registry`, `Auth`, `Audit`) — rejected: the `app/Mcp` base folder `laravel/mcp` generates by
  default, because a new base folder needs an explicit decision (AGENTS.md, Directory Boundaries).
- Transport: Streamable HTTP (`Mcp::web('/mcp', …)`) in its own route file — rejected: a local/stdio
  server for tenant traffic; stdio stays dev-tooling only, never a tenant-facing transport.
- Tenant scoping: a token resolves its tenant through `TenantSwitcher::runForTenant()`, restored in
  `finally`; no tenant context is fail-fast, never a default tenant — rejected: any implicit default
  tenant fallback; no tool reaches the landlord connection directly, only through `Tenancy/Contracts`.
- Authorization: reuse the existing `Permission` enum and Policy classes — a tool asks `Gate`, not a
  bespoke check; `Permission::isSensitive()` maps to a separate token scope — rejected: a parallel,
  MCP-specific authorization model.
- Extensibility: `McpToolRegistry`, mirroring `ActionHandlerRegistry` / `RagAdapterRegistry`; the
  `McpToolInterface` contract lives in `packages/fapost-foundation` — rejected: Core knowing about
  Solution-specific tools (e.g. `hr.*`) directly; Solutions register their own tools (ties to M7).
- Ingress: MCP ingress is stateless, like webhook ingress — tenant context and the current server live
  only in `scoped` bindings — rejected: any static state carried between requests, forbidden because
  Horizon workers are long-lived and such state leaks across tenants between jobs (AGENTS.md,
  Long-Lived Worker Safety).
- Operation safety: v1 is read-first; destructive operations (delete, publish, send) sit behind a
  separate scope, carry `destructiveHint`/`readOnlyHint` annotations, and require an idempotency
  marker on the write path — rejected: shipping ungated write tools in v1.
- Audit and limits: every call is written to `mcp_audit_log` (tenant, token, tool, argument hash,
  outcome, duration); rate limiting applies per token.

Open (not yet decided, resolved by the Phase 1 ADR, not by this spec):

- Dependency: `laravel/mcp` vs a custom JSON-RPC implementation — the project's rule against adding
  dependencies without agreement (AGENTS.md, Laravel And PHP Rules) applies, so this is settled in the
  ADR the source calls for, not here.
- Token model: the project has no Sanctum installed today; whether `mcp_tokens` is built on Sanctum or
  as a standalone token implementation is settled in the same ADR.

## Open questions

- One MCP server for the whole platform, with tenant scope resolved from the token, vs a separate URL
  per tenant — undecided; shapes the routing/transport design in Phase 1.
- Whether OAuth 2.1 is needed before a plugin marketplace exists, or bearer tokens are sufficient —
  undecided; shapes the token model decided in the Phase 1 ADR.
- Whether MCP Apps (interactive HTML panels rendered in the client) are needed — undecided; the source
  flags a risk of duplicating Filament UI; would affect the scope of Phase 4/5.

## Assumptions left untested

- `laravel/mcp`'s Streamable HTTP transport integrates cleanly with tenant-scoped middleware and a
  stateless-ingress design — taken at normal depth from the source's transport decision; tested by a
  Phase 1 spike once the dependency question is resolved.
- The existing `Permission` enum and Policy classes cover MCP's scope needs (including a distinct
  "sensitive" scope) without a new policy surface — taken at normal depth; tested while wiring
  `mcp.auth` in Phase 1.
- Domain services such as `ValidateFlowService`, `PublishFlowService` and `ContactSegmentResolver` can
  back MCP tools unchanged — taken at normal depth from the source's Phase 2/3 item list; tested when
  each tool is implemented.
- The product-priority "out of scope" status recorded in `docs/roadmap.md` does not block resuming this
  engineering plan ahead of step 8 (Ecosystem distribution) — not tested here; it is a product-priority
  call for a human, not an engineering assumption this spec can resolve.
