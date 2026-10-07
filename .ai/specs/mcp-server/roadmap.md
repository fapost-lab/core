# Roadmap — MCP Server

Destination: external AI agents (Claude, ChatGPT, a tenant's own agent) can read FaPost's assistants,
flows, contacts/segments, conversations and broadcasts, and — behind a separately gated scope —
operate on them, through a tenant-scoped, audited Model Context Protocol server that Solutions can
extend with their own tools.

## Phase 1 — Foundation

Goal: an authenticated, tenant-scoped MCP endpoint exists, with the extension points it needs in
place, before any tool is registered on it. Done when: a bearer token resolves to a tenant through
`TenantSwitcher`, a request without tenant context is rejected fail-fast, and a call — even against an
empty tool registry — is written to `mcp_audit_log`.

- [ ] Architecture decision recorded: an ADR covering transport, domain boundaries, auth model, scopes
      and audit, including the two decisions the source leaves open — `laravel/mcp` vs a custom
      JSON-RPC implementation, and the token model given no Sanctum is installed today
- [ ] MCP domain scaffold: `Domains/Mcp` service provider plus a `/mcp` route group serving Streamable
      HTTP (after: the ADR above — it fixes the domain boundaries and transport before they're built)
- [ ] Token issuance and tenant-scoped auth: `mcp_tokens` migration with issue/revoke, plus
      `mcp.auth`/`mcp.tenant` middleware that fails fast without tenant context (after: the ADR above —
      it picks the token model before the migration is written)
- [ ] Tool contract and registry: `McpToolInterface` in `packages/fapost-foundation` plus
      `McpToolRegistry` in Core, ready for the first tool to register against (after: the domain
      scaffold — the registry needs the service provider to attach to)
- [ ] Audit and rate limiting: every call writes to `mcp_audit_log` (tenant, token, tool, argument
      hash, outcome, duration), and rate limiting applies per token (after: the domain scaffold — audit
      needs the request pipeline to hook into)
- [ ] Test harness for tools, plus a phpat rule that a tool cannot reach the landlord connection
      directly or pull in the UI layer (`App\Http` or the front end) (after: the tool contract and registry — nothing to exercise without
      them)

## Phase 2 — Read tools (v1)

Goal: an external agent can read the platform's current state without being able to change anything.
Done when: each read tool below returns tenant-scoped data through the registry, appears in the audit
log, and no tool in this phase performs a write.

- [ ] Read access to assistants: `assistants.list` / `assistants.get`
- [ ] Read access to flow definitions and their validation: `flows.list` / `flows.get` /
      `flows.validate` (via `ValidateFlowService`)
- [ ] Read access to contacts and segments: `contacts.search` / `contacts.get`, `segments.list` /
      `segments.preview` (via `ContactSegmentResolver`)
- [ ] Read access to conversations: `conversations.search` / `conversations.transcript`
- [ ] Read access to broadcasts: `broadcasts.list` / `broadcasts.stats`
- [ ] Runtime diagnostics for stuck sessions: `flow_sessions.inspect` / `flow_logs.tail`

## Phase 3 — Write tools (gated)

Goal: an external agent can, within its granted scope, change tenant data and trigger sends or
publishes safely. Done when: every write tool below requires its own scope, retrying the same call
does not double-apply its effect, and every write lands in `mcp_audit_log`.

- [ ] Contact write access: `contacts.set_tag` / `contacts.update_attributes`
- [ ] Segment authoring: `segments.create` / `segments.update`, including recount
- [ ] Broadcast drafting and sending: `broadcasts.create_draft`, with the actual send gated behind the
      `broadcast:send` scope
- [ ] Flow publishing: `flows.publish` via `PublishFlowService`, gated behind `flow:publish`,
      publishing only a valid graph
- [ ] Sending into an existing conversation: `messages.send`, tagged `origin=mcp` and logged to the
      transcript
- [ ] Idempotency markers and audit coverage confirmed on every write path above (after: the write
      tools above — there is nothing to verify until they exist)

## Phase 4 — Resources and Prompts

Goal: the same information is reachable as addressable resources, not only through tool calls, and
common multi-step tasks are packaged as prompts. Done when: an MCP client can fetch a resource by URI
with pagination on large result sets, and invoke a shipped prompt that composes existing tools end to
end.

- [ ] MCP resources for flow/conversation/schema/docs — `flow://{id}/definition`,
      `conversation://{id}/transcript`, `schema://variables`, `docs://node/{type}` — as resource
      templates with pagination
- [ ] Prompts that package a workflow: diagnosing a stuck session, building a segment from a
      description, drafting a flow from a description (after: the Phase 2/3 tools — prompts compose
      tools that must already exist)
- [ ] Media delivery decision applied consistently: blob vs signed URL, used across every resource and
      tool that returns media

## Phase 5 — Extensibility and UI

Goal: the MCP surface is usable by tenants and extensible by Solutions, not only by Core developers
exercising tools directly. Done when: a Solution's tool appears in the registry after install with no
Core change, and an operator can issue a token and connect an agent using only the generated config and
the console.

- [ ] Solution-registered tools proven end to end: a Solution registers its own tools through
      `McpToolRegistry`, and installing the Solution makes its tool available without touching Core
- [ ] Operator-facing token management: an MCP Tokens screen in the console, built on the kit
      (issue/revoke/scopes/last used) plus an audit log view (after: ui-foundation phase 2 — the
      screen is built on the kit)
- [ ] Agent onboarding: generated client config (URL plus headers) for connecting an agent
- [ ] Developer documentation: a `developers/` page explaining how to write an MCP tool inside a
      Solution

## Waves

1. Phase 1's ADR (transport, domain, auth model, scopes, audit, plus the dependency and token-model
   choices) — blocks everything else and must land first.
2. Phase 1's domain/route scaffold and its token issuance/auth middleware — independent of each other
   once the ADR is settled.
3. Phase 1's tool registry, audit/rate-limit wiring, and test harness — depend on wave 2's scaffold,
   independent of each other.
4. Phase 2's six read-tool groups — independent of each other, each touching a different domain.
5. Phase 3's write-tool groups — independent of each other; each depends only on its own domain's
   Phase 2 read tools already existing.
6. Phase 4 — the resource templates can start once Phase 1 lands; the prompts need Phase 2/3 tools in
   place first; the media decision applies once both exist.
7. Phase 5 — Solution registration, the console token screen, onboarding config and developer docs are
   independent of each other, and come last because each needs a stable registry and working tools to
   plug into or document.

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
