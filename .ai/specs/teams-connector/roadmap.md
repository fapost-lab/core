# Roadmap — Microsoft Teams connector

Destination: a company's Teams users talk to a FaPost assistant through an optional
`fapost/connector-teams` package, installed by the company's admin.

## Phase 1 — The shape

Goal: the idea is tested and the open questions have answers. Done when: the spec is resumed with
`jig-idea` at normal depth and its roadmap has real items.

- [ ] fog: market check — which Ukrainian companies, which use cases, Teams or another tool; decides
      whether Teams is the next channel
- [ ] fog: registration model — one multi-tenant app versus an app per customer, against Azure's current
      bot registration rules
- [ ] fog: contract additions — JWT ingress (in the gateway or proxied), OAuth-token sender,
      conversation references, proactive messages; waits on Slack proving the `channel-connectors` contract

## Waves

1. market check; registration model
2. contract additions

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
