---
id: convention-id-strategy
type: convention
status: active
domains: []
paths:
  - "app/Domains/*/Models/**"
  - "database/migrations/**"
summary: Tenant-schema PKs are ULIDs in uuid columns via HasUlidPrimaryKey, per ADR-03
reviewed_at: 2026-10-05
---
# Tenant-schema ID strategy

## Practice

Tenant-schema primary keys are ULIDs stored in a PostgreSQL `uuid` column, never a
database-generated UUID or an auto-increment integer. A model that follows this
strategy uses `Fapost\Support\Concerns\HasUlidPrimaryKey`, which layers Laravel's
`HasUlids` and forces the generated value to a lower-cased RFC-4122 string. Its
migration declares the column as `$table->uuid('id')->primary()` with no database
default — the trait generates the value in PHP, not the database — and a foreign
key to it is `foreignUuid(...)->constrained()->cascadeOnDelete()`, or the local
project's equivalent phrasing where cascading differs. Special public identifiers
such as the webhook public hash are not ULIDs and are not changed to fit this
strategy without a separate decision.

## Example

`app/Domains/Assistant/Models/Assistant.php` uses `HasUlidPrimaryKey`; its tenant
migration (`database/migrations/tenant/2026_03_27_210000_create_assistants_table.php`; the same
pattern is in `2026_04_02_100000_create_contacts_table.php`) declares `$table->uuid('id')->primary()` with no
default. `PreSaleRequest` (`app/Domains/Presale/Models/PreSaleRequest.php`) is the
documented exception: it stores public landing-page submissions, not tenant-schema
data, so it keeps an auto-incrementing integer `id`.

## Rationale

ADR-03 (`docs/platform/architecture/adr/03-id-strategy-ulid.md`) chose ULIDs over
auto-increment or a raw UUIDv4 for tenant-schema keys: they sort by creation time,
are safe to generate outside a central sequence (client-side, in a queued job, on a
worker that has switched tenant), and never collide across tenants.
`tests/Architecture/IdStrategyTest.php` enforces it as a PHPat rule over every class
in `App\Domains\*\Models`, excluding enums and two named exceptions: `PreSaleRequest`
(not tenant-schema data) and `FlowCallgraphEdge`, whose composite primary key
(`caller_flow_id`, `callee_flow_id`, `caller_definition_id`) leaves no surrogate `id`
column for a ULID to occupy. Any other model that skips `HasUlidPrimaryKey` fails
`composer run test:arch`.
