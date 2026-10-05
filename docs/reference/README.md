# Reference

This section contains detailed design specs, diagrams and schema references.

Use it when a platform guide or developer guide points you here. It is intentionally detailed and
should not be the main onboarding path. The published documentation at https://docs.fapost.in is the
single source of truth for users and extension authors; plans live in `.ai/specs/`.

## Specs

See [specs/README.md](./specs/README.md) for what this folder is and is not.

- [specs/flow-engine](./specs/flow-engine) - Flow Engine design specs: state model, concepts, call transport, validation.
- [specs/flow-engine/nodes](./specs/flow-engine/nodes) - per-node specs.
- [specs/builder/storage](./specs/builder/storage) - builder variable storage specs.
- [specs/messaging/conversation-logging.md](./specs/messaging/conversation-logging.md) - Conversation Logging design.

## Diagrams

- [diagrams](./diagrams) - Mermaid diagrams for key runtime flows.

## Schema References

- [builder-config-schema-reference.md](https://docs.fapost.in/reference/builder-config-schema) - schema returned by node handlers and used
  by the Vue builder.

## Generated API

Run `composer run docs:build` to generate Doctum API docs into `public/core`. The generated site covers Core domains and
public package contracts.
