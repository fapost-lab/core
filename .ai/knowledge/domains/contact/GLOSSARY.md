---
id: glossary-contact
type: glossary
status: active
summary: Contact, ChannelContact, tag, group, segment and conditions, deliverable contact, platform
domains:
  - contact
topics: []
load: domain
paths:
  - "app/Domains/Contact/**"
  - "app/Filament/Assistant/Resources/Contact*/**"
  - "database/migrations/tenant/*contact*"
  - "tests/*/Domains/Contact/**"
reviewed_at: 2026-10-05
---
# Contact glossary

## Contact

A person an assistant talks to, identified per tenant by platform and external id. Informal
synonyms: user, subscriber, client (avoid — "user" means a staff user).

## ChannelContact

The link between a contact and one channel, carrying `last_interaction_at`.

## Tag

A free-form string on a contact, unique per contact.

## Contact group

A named list with explicit members.

## Segment

A saved rule set — `{match, conditions[]}` — evaluated on demand, with a `cached_count`.
Condition types: `tag`, `language`, `platform`, `attribute`, `group`.

## Deliverable contact

A contact with an active channel link for the assistant in question; the audience a notify or a
broadcast can actually reach.

## Platform

`PlatformEnum` (`telegram`, `whatsapp`, `email`): the platform half of a contact's identity.
