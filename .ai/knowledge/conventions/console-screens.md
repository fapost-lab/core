---
id: convention-console-screens
type: convention
status: active
domains: []
paths:
  - "app/Http/Controllers/Console/**"
  - "app/Http/Controllers/Admin/**"
  - "app/Http/DataTable/**"
  - routes/inertia.php
  - "resources/js/pages/Console/**"
  - "resources/js/ui/**"
reviewed_at: 2026-10-09
---
# Console screens

## Practice

A screen moved off Filament copies the contact-groups pilot; deviate only with a reason.

- **Routes.** The screens Filament served keep their method, URI, name and parameters
  (`{tenant}`, `{record}`) in `routes/inertia.php`; writes Filament never had as routes get
  `console.*` names. Laravel passes route parameters to the action by position, so an action
  under `assistant/{tenant}/…/{record}` takes `string $tenant, string $record` even when it
  ignores `$tenant`.
- **Controller orchestrates, a domain service writes.** The service finds records inside the
  current tenant (another tenant's id is a 404, a malformed one too — check `Str::isUuid` before
  Postgres sees it), creates, updates and deletes. Every action authorizes; writes go through a
  FormRequest whose `authorize()` uses the policy and whose rules repeat the database's unique
  indexes, so a duplicate is a 422, not a 500.
- **Lists use `App\Http\DataTable\DataTable`** with whitelisted sortable and searchable columns
  and a row-mapping callback; its prop shape (`rows`, `meta`, `state`, `defaults`) feeds the kit's
  `DataTable` component, which keeps search, sort, page and page size in the query string.
- **After a write**, toast through `Inertia::flash()` (never the session `with()`: a shared prop
  is kept in history and replays on Back). Deletes go `back()` so the list keeps its state; forms
  go to the index.
- **Pages** live in `resources/js/pages/Console/<Screen>/`, use `AppShell` as layout, read their
  strings from `page.props.translations.console.<screen>` (en, ru, uk together), and build from
  `@fapost/ui` components: `data-table`, `form-field`, `confirm-dialog`. Permission flags for
  buttons come from the controller as `can`.
- A bulk delete as one query skips model events; say so on the method, and delete model by model
  where observers matter. A delete with a guard (a group that still holds flows, a flow with live
  sessions) is always per record: the service throws a domain exception, the controller turns it
  into `Inertia::flash('error')`, and a bulk delete skips the blocked records and reports both
  counts.
- **The console has no Filament tenancy scope.** Every service query filters `tenant_id` and, for
  assistant-owned records, `assistant_id` itself. A policy that checks the record's assistant
  (`FlowDraftPolicy`) gets its `can` flags from an unsaved model with the current assistant set
  as its relation (`FlowDraftService::abilityProbe()`); a bare `new Model()` has no assistant and
  answers false.
- **Filters and grouping** go through `DataTable`'s optional `filters` (`filter[key]`) and `groups`
  (`group=key`) — whitelisted closures; their keys appear in the props only on a table that
  declares them. The kit's `data-table` draws group headers from a `groupOf` callback.
- **The builder is a separate Inertia app**: reach it with a plain `<a>` or `Inertia::location()`
  to the named route `builder.flows.show`, never a `<Link>` or a redirect. A related record
  created from inside a form (a flow's new group) uses its own `*-inline` route that redirects
  back with the new id in the flash.

## Example

`ContactGroupController`, `ContactGroupService`, `ContactGroupRequest`,
`DestroyContactGroupsRequest`, `resources/js/pages/Console/ContactGroups/*`,
`tests/Feature/Console/ContactGroupsConsoleTest.php`. With filters, grouping, guards and an
assistant-owned policy: `FlowController`, `FlowDraftService`, `resources/js/pages/Console/Flows/*`.

## Rationale

About thirty screens move the same way. One worked pattern, reviewed once, keeps tenant isolation,
authorization and list behaviour identical across them, and makes a screen that differs stand out
in review.
