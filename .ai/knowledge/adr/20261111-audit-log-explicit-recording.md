---
id: adr-20261111-audit-log-explicit-recording
type: adr
status: accepted
date: 2026-11-11
domains:
  - audit
  - staff
paths:
  - "app/Domains/Audit/**"
  - "app/Infrastructure/Audit/**"
  - config/activitylog.php
  - config/audit.php
  - app/Console/Commands/PruneActivityLogCommand.php
  - tests/Architecture/AuditBoundariesTest.php
---
# The audit log is recorded explicitly by the console's domain services, in their transaction, into the tenant's schema

## Context

Staff asked to see who changed what in the console (owner, 2026-10-15). `spatie/laravel-activitylog` (v5) had been
in `composer.json` since the first commit, unused. Spec `audit-log` had already settled storage in the tenant's
schema, a configurable retention with a scheduled prune, a layer apart from the conversation transcript and a screen
on the kit. Forces: almost every model in scope is also written by the runtime or by jobs (`Contact` on every flow
step, `Channel` by webhook recorders, `Broadcast` by fan-out jobs, `User` on every sign-in); a channel's secrets
must never leave the request that uses them; workers are long-lived and switch tenants; the platform support user
is one account shared by every operator.

## Decision

- **Explicit recording.** Domain services on the console's and builder's write paths call
  `App\Domains\Audit\Contracts\AuditTrailInterface::record(AuditRecord)` with an action from the catalogue enum
  `AuditAction` (a stable machine key, stored as `description`; its area is `log_name`; the text comes from
  `lang/{locale}/audit.php`). No `LogsActivity` trait, no `activity()` helper.
- **Same transaction, fail-closed.** The change and its `record()` share one `DB::transaction`; a service whose write
  had none opens one around both (the channel's provider calls stay outside: the observer defers them after the
  commit, and the service returns the channel as stored after it). A rolled-back change leaves no entry and an entry that cannot be written fails the change
  (`AuditEntryNotRecordedException` also when the package's logging is disabled). Exception: the sign-in and
  sign-out listeners fail open (`report()`), in a savepoint, so a broken log never locks staff out.
- **Storage.** The package's `activity_log` table, created by Core's own tenant migration with ULID keys in `uuid`
  columns and `nullableUuidMorphs`, plus `actor_kind`, `actor_label`, `subject_label`, `assistant_id`; model
  `ActivityEntry extends Spatie\Activitylog\Models\Activity` with `HasUlidPrimaryKey`, no `updated_at`. No global
  morph map (it would change the morph class of every model, and `model_has_roles`/`notifications` hold FQCNs).
  `config/activitylog.php` fixes `enabled = true` and `buffer.enabled = false`, not from the environment.
- **Whitelist per action.** Each call names the fields it records: values (old -> new) for names, statuses, flags,
  languages, role permissions; markers (the field name only) for texts, a channel's config, segment rules, the
  password; never tokens, hashes or activation tokens. Contacts carry markers only and a `Contact · <short id>`
  label: the log outlives a deleted contact and must not become a copy of personal data.
  `default_except_attributes` is applied by `AuditTrail` to values and properties as a safety net, not as the
  mechanism.
- **No singleton records.** The trail is `scoped`; a service that takes it is bound `bind` or `scoped`, never
  `singleton` (`RoleWriterService` was a singleton until this task), or the first job's actor and log status would
  serve every later one.
- **Actor.** `AuditActorResolver` (in `App\Infrastructure\Audit`, scoped) reads the `web` guard at call time:
  `staff` (the user, name frozen), `support` (the platform support user, the operator's name and
  `support_entry_id` from the support session), `system` when nobody is signed in (jobs, commands). The causer is
  always set explicitly.
- **Boundaries.** `App\Domains\Audit` depends on no other domain except the Tenancy contracts; what knows Staff
  or Tenancy internals (resolver, policy, listeners, provider, the platform setting) lives in
  `App\Infrastructure\Audit`. The flow runtime, Messaging, Webhook and `Broadcasting\Jobs` must not depend on
  Audit. Both enforced by `tests/Architecture/AuditBoundariesTest.php`.
- **Retention.** Platform setting `audit.retention_days` (default 365 from `AUDIT_RETENTION_DAYS`, 0 = forever,
  max 3650); `audit:prune` walks the active tenants with `runForTenant()` and deletes in batches, daily at 04:15.
  The package's `activitylog:clean` is not used.
- **Reading.** `admin/activity` (`console.admin.activity.index`), behind the new sensitive permission
  `view_activity_log` (administrators through `Gate::before`; a tenant migration adds the row for existing tenants).
- **Scope of v1.** Staff actions in the console and builder (catalogue in `AuditAction`), sign-in and sign-out,
  workspace deletion requests (from Tenancy's events). Not recorded: draft autosaves, job-driven status changes,
  Solution activations (`solution_activation_events` stays their only journal), operator actions through
  Foundation contracts, the support user's own sign-in (it is in `support_access_entries`).

## Alternatives

- `LogsActivity` on the models: an insert (and a `fresh()` select) on the message hot path, system noise recorded as
  people's actions, no domain verbs, secrets kept out only by a blacklist.
- A hybrid (trait on quiet models, explicit elsewhere): two mechanisms, two ways to leak.
- Recording in controllers: outside the service's transaction, and duplicated across the two panels.
- After commit (`afterCommit`) or through a queue: an action can pass unrecorded; a queue loses the request's actor
  and support session.
- The package's buffer: flushes after a worker restored the tenant (another schema) and bulk-inserts past ULIDs.
- A landlord table: rejected by the spec; the log belongs to the tenant and goes with it.
- Retention per tenant: a tenant could erase the trace of its own actions; possible later if asked.

## Consequences

- A new write path in the console needs its own `record()` call; nothing catches a missing one automatically —
  review, and the catalogue enum in one place.
- A failing insert into `activity_log` fails the staff action (fail-closed); the table has no foreign keys.
- Renaming a model class orphans `subject_type`/`causer_type` of old entries; the screen does not depend on them.
- Workspace deletion is recorded from Tenancy's events: the request is a landlord row, the entry a tenant row, so
  they are not one transaction across connections.
- `support_access_entries` is linked to, not absorbed (amendment to
  `adr-20261007-support-access-through-platform-support-user`).

Source: task `activity-log`, spec `audit-log`; design approved by the owner on 2026-11-11.
