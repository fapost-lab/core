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
  - "app/Http/Controllers/Console/Contact*"
  - "resources/js/pages/Console/Contacts/**"
  - "app/Http/Requests/Console/*Contact*"
  - "resources/js/pages/Console/ContactSegments/**"
reviewed_at: 2026-10-09
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
  rule set matches everyone. That includes a `group` condition that names a group which no longer
  exists (or is another tenant's), under `in` and `not_in` alike: `not_in` of a vanished group must
  not match every contact. Why *(inferred)*: an unknown condition must never widen a broadcast's
  audience. Enforced: `ContactSegmentResolverTest`.
- **`SegmentConditionType` decides which operators a condition type takes and how many values
  (`operators()`, `arity()`, `needsKey()`).** The Filament form offers them, the console sends them
  to the client as its schema and validates a save against them, and the resolver reads a
  condition the same way (a `One` condition uses the first value only). Why: two forms and the
  resolver used to disagree, and a value the resolver ignores is a silent mistake in an audience.
  Enforced: `SegmentConditionTypeTest`, `ContactSegmentsConsoleTest`.
- **Attribute keys are validated against a pattern before they reach SQL**
  (`ContactSegmentResolver`).
- **The notify fan-out runs once per `(session, node)`** (a `Cache::add` guard in
  `SendContactNotificationJob`).

- **A contact is visible in an assistant's console only through `channel_contacts` to
  `channels.assistant_id`, and every query filters `tenant_id` explicitly.** A contact belongs to the
  tenant and has no assistant of its own; the console has no Filament tenancy scope, so a filter on
  the tenant alone would show a sibling assistant's contacts. Enforced: `AssistantContactService`,
  `ContactsConsoleTest`.

## Rules

- **The "deliverable contact" query and the language fallback exist twice** — the query in
  `SendContactNotificationJob::deliverableChannelContacts` and in Broadcasting's
  `BroadcastRecipientResolver`; the language fallback in `resolveLanguage` of
  `SendContactNotificationJob` and of Broadcasting's `SendBroadcastRecipientJob`. Change both
  together, or extract them first. Review only. *(proposed)*
