---
id: convention-migration-column-changes
type: convention
status: active
domains: []
paths:
  - "database/migrations/**"
summary: A ->change() migration restates every attribute the column keeps (length, nullability, default), in up() and down(), or Laravel drops it
---
# Changing a column

## Practice

A migration that modifies an existing column with `->change()` restates **every attribute the
column is meant to keep**: type, length, nullability, default, unsigned, comment. Laravel does not
merge the new definition into the old one; it replaces the column definition, and any attribute
left out is dropped.

The same holds for `down()`: it restates the column as it was before `up()`, not only the
attribute `up()` changed.

Indexes are not column attributes and are kept by the database across the change; say so in the
migration's docblock when a reader might wonder.

## Example

`database/migrations/tenant/2026_10_16_000001_widen_channel_type_and_contact_platform.php`
widens two `string(20)` columns to 32 characters. Both columns are required, so
`$table->string('type', 32)->change()` carries no `->nullable()`, and `down()` restores
`string('type', 20)` rather than a bare `->change()`. Had either column been nullable or had a
default, `->nullable()` / `->default(...)` would have to appear in both directions.

## Rationale

The loss is silent: the migration succeeds, tests that only write valid values pass, and the
dropped `NOT NULL` or default shows up later as bad data in production. The rule used to live in
the Laravel Boost guidelines block of `AGENTS.md`, which Boost regenerates per framework version
and dropped when the project moved to Laravel 13; it is kept here because it does not depend on
that version.
