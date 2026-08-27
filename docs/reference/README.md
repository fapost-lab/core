# Reference

This section contains detailed contracts, specs, diagrams, and schema references.

Use it when a platform guide or developer guide points you here. It is intentionally detailed and should not be the main
onboarding path.

## Specs

- [specs/flow-engine](./specs/flow-engine) — Flow Engine runtime contracts.
- [specs/flow-engine/nodes](./specs/flow-engine/nodes) — per-node specs.
- [specs/builder](./specs/builder) — builder renderer, storage, and page structure specs.
- [specs/auth](./specs/auth) — permissions, policies, and roles UI.
- [specs/messaging/conversation-logging.md](./specs/messaging/conversation-logging.md) — Conversation Logging design.

## Diagrams

- [diagrams](./diagrams) — Mermaid diagrams for key runtime flows.

## Schema References

- [builder-config-schema-reference.md](https://docs.fapost.in/reference/builder-config-schema) — schema returned by node handlers and used
  by the Vue builder.

## Generated API

Run `composer run docs:build` to generate Doctum API docs into `public/core`. The generated site covers Core domains and
public package contracts.
