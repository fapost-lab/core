---
id: rule-contact
type: rule
status: active
summary: Idempotent contact and tag creation, fail-closed segments, duplicated audience logic
domains:
  - contact
topics: []
load: domain
requires: []
paths:
  - "app/Domains/Contact/**"
  - "app/Filament/Assistant/Resources/Contact*/**"
  - "database/migrations/tenant/*contact*"
  - "tests/*/Domains/Contact/**"
---
# Contact rules

Each entry names what enforces it. "Review only" means nothing fails automatically when it is
broken.

## Invariants

- **Creating a contact is idempotent.** Unique `(tenant, platform, external_id)` plus a fallback
  on the unique-violation error. Why: two webhook deliveries for a new contact can race.
  Enforced: `ContactService`, `ContactServiceTest`.
- **Adding a tag is idempotent** (a savepoint around the insert in `ContactTagRepository`).
- **A segment fails closed.** A condition the resolver cannot evaluate matches nobody; an empty
  rule set matches everyone. Why *(inferred)*: an unknown condition must never widen a
  broadcast's audience. Enforced: `ContactSegmentResolverTest`.
- **Attribute keys are validated against a pattern before they reach SQL**
  (`ContactSegmentResolver`).
- **The notify fan-out runs once per `(session, node)`** (a `Cache::add` guard in
  `SendContactNotificationJob`).

## Rules

- **The "deliverable contact" query and the language fallback exist twice** — in
  `SendContactNotificationJob` and in Broadcasting's `BroadcastRecipientResolver`. Change both
  together, or extract them first. Review only. *(proposed)*
