# Architecture Vision

## Role of FAPost Core

FAPost Core is the runtime and domain platform for conversational automation. Core must not contain SaaS logic, billing,
onboarding, or other control plane concerns.

Expected ecosystem:

- `FAPost Core` as the shared execution kernel
- separate solution repositories (`HR`, `Niche`, and others)
- a separate SaaS repository on top of core

## Core Constraints

- Core always runs inside a tenant context.
- Self-hosted and SaaS must not create two different core architectures.
- Tenant runtime is mandatory; fallback to a "default tenant" is forbidden.
- Access to landlord-level data from core is allowed only through the tenancy domain.

## Current Use of This Document

This model is an architectural contract for designing new domain modules. Implementation status belongs in
[`../current-state.md`](../current-state.md) and [`../TASKS.md`](../TASKS.md), not in this document.

Detailed context:

- [`../PROJECT.md`](../PROJECT.md) - project, product, and architecture overview.
- [`platform/01-overview-layers.md`](./platform/01-overview-layers.md)
  - platform layer model.
- [`platform/12-solutions-modules.md`](./platform/12-solutions-modules.md)
  - Solutions and Plugins.
- [`platform/13-self-hosted.md`](./platform/13-self-hosted.md) -
  self-hosted delivery model.
