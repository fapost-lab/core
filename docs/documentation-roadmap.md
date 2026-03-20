# Documentation Roadmap

This file acts as a working backlog for developer documentation.

## Already Prepared

- entry-point `README.md`;
- getting started guide;
- current project state overview;
- architecture section scaffold.

## Next Priorities

1. Document local infrastructure: PostgreSQL, Redis, and possible Docker setup.
2. Lock down the process for adding a new domain under `app/Domains`.
3. Describe boot lifecycle and registries in a dedicated document.
4. Prepare an ADR template for architecture decisions.
5. Add a testing strategy section: unit, feature, integration, and tenant-aware tests.

## Useful Principle

New documents should be added around concrete developer questions:

- "how do I run the project";
- "where should this code live";
- "how does runtime work";
- "which constraints must not be violated".
