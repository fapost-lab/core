---
id: glossary-broadcasting
type: glossary
status: active
summary: Broadcast and recipient statuses, target, backpressure, chunk size, localized message
domains:
  - broadcasting
topics: []
load: domain
paths:
  - "app/Domains/Broadcasting/**"
  - "app/Filament/Assistant/Resources/Broadcasts/**"
  - "database/migrations/tenant/*broadcast*"
  - "tests/Feature/Domains/Broadcasting/**"
reviewed_at: 2026-10-05
---
# Broadcasting glossary

## Broadcast

A one-off message to an audience. Status: `Draft` → `Running` → `Completed`, or `Cancelled`.
`Failed` exists in the enum but nothing sets it. Informal synonyms: campaign, mailing.

## Target

The audience rule: `All`, `Tags` or `Segment`.

## Recipient

One contact's row in a broadcast. Status: `Pending` → `Sent`, `Failed` or `Skipped`.

## Backpressure

The tenant-level switch that delays a broadcast run while the `messaging.broadcast` queue is
longer than 1000 jobs.

## Chunk size

How many recipients are dispatched per second of delay.

## Message

The broadcast body, stored as a map of locale to text.

## Revision

What a person confirms when sending from the console: a SHA-256 of a draft's name, message, target
type, tags and segment. A send carries it, and the service refuses it when the draft changed since.

## Reach

How many contacts a broadcast to an audience would reach now (deliverable contacts, counted once
each). An estimate shown before sending; the audience is fixed again when the run starts.
