# Specs

Design specs and runtime contracts that stay useful after the code exists. The ones an agent needs
are linked into `.ai/knowledge/` (see `.ai/knowledge/sources/`) and reach a task through
`jig context`.

## Sections

- `builder/storage/` - builder variable storage, pickers and coercion.
- `flow-engine/` - state model, common concepts, call transport, validation, per-node specs.
- `messaging/` - conversation logging.

## Rule

Plans, roadmaps and progress live in `.ai/specs/` (see `.ai/knowledge/conventions/docs-layout.md`),
not here. This folder holds design specs that describe how a built part behaves. Superseded
material is moved to `docs/archive/`.
