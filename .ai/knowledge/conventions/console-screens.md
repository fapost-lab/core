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
reviewed_at: 2026-10-10
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
  Rows that come from a catalogue rather than a table (translation keys) use
  `App\Http\DataTable\ArrayDataTable`: same query string and props, filtering in memory.
- **An admin-panel screen** is a controller in `App\Http\Controllers\Admin\` on the `admin` stack; the
  shell renders it in admin mode with `NavigationBuilder::admin()`. A screen both panels share keeps
  one page and one domain service, and each controller fixes the scope it writes to (tenant or
  assistant) — the request never chooses it. Example: `Admin\TranslationController`,
  `Console\TranslationController`, `TranslationOverrideEditor`, `TranslationScope`.
- **After a write**, toast through `Inertia::flash()` (never the session `with()`: a shared prop
  is kept in history and replays on Back). Deletes go `back()` so the list keeps its state; forms
  go to the index.
- **Pages** live in `resources/js/pages/Console/<Screen>/`, use `AppShell` as layout, read their
  strings from `page.props.translations.console.<screen>` (en, ru, uk together), and build from
  `@fapost/ui` components: `data-table`, `form-field`, `confirm-dialog`. Permission flags for
  buttons come from the controller as `can`.
- **The kit follows the approved mockups, not stock shadcn-vue** (the design canvas linked from the
  `ui-foundation` spec): a page adds no colours or type of its own. A state (active, inactive)
  is a `StatusDot`; a category or count is a soft `Badge` chosen by meaning (`info`, `neutral`,
  `success`, `warning`, `danger`); a form is a stack of `FormSection`s (title and description
  left, fields right); an empty list is `EmptyState`; a bulk delete is the `outline-danger`
  button. A new colour need becomes a token in `tokens.css` with its dark value.
- **Choices that depend on another field** (a condition's operators by its type, how many values each
  takes) reach the client as a prop built from one domain source (an enum's methods); the client holds
  no literal of them, and the FormRequest validates the same pair from the same source. Example:
  `ContactSegmentController::schema()`, `ContactSegmentRequest`, `resources/js/pages/Console/ContactSegments/rules.ts`.
- **Dictionaries with keys from data** (contact attributes, platform meta) go into props as lists of
  `{key, value}`: a JSON object reorders numeric keys in JS, and `jsonb` reorders all of them.
  When such a form shows stored values as text, a row whose key and shown value come back unchanged
  keeps the stored value and its type; only an edited or new row is saved as a string, so saving
  another tab never rewrites `3` as `"3"`. Example: `AssistantSettingsService::settingsMap()`.
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
- **Secrets never reach the browser.** A token or key is in no prop, row or flash message (one deliberate exception:
  the new webhook hash is flashed once after a rotation, to the one who may rotate): the edit form
  shows it empty and an empty value means "keep what is stored". When an external provider refuses after the
  record is saved, the write still succeeds; the toast is a fixed, translated error chosen from the stored outcome
  (the channel's status, or the request's sync outcome), never from an exception, whose message can carry the
  request URL and with it the secret. Example: `ChannelController`.
- **A step that reaches people carries what the person confirmed and is conditional on the status.**
  The confirmation dialog holds the row's `revision`; the service locks the record, compares it, and
  the write itself carries the status in its `WHERE`, so a double click, a second tab or a replayed POST
  does the step once. A list that shows live progress polls (`usePoll`, partial reload of `table` only)
  only while a row is running, and gives up after a fixed time. Example: `BroadcastController`,
  `BroadcastService`, `SendDialog.vue`.
- **A screen that follows live activity** takes a `live` prop (`{channel, event}`) from its controller
  and calls `useLiveUpdates`: Echo when a broadcaster delivers and a client is registered, polling
  otherwise. The server announces only after the commit, only while a screen marks itself watched
  (`FlowActivityWatchers`), throttled per assistant, and a failed announcement is reported, never
  thrown into the flow step. The event carries no data: the page reloads its own props. Example:
  `FlowSessionController`, `FlowActivityNotifier`, `resources/js/ui/lib/useLiveUpdates.ts`.
  The browser learns how to reach the socket at runtime from the shared `broadcaster.client` prop
  (never a secret, `null` for guests), not from `VITE_*` build variables: self-hosters run prebuilt
  images. The Echo client is a lazy chunk and counts as registered only while its connection is
  `connected`; a lost socket or a refused channel puts the screen back on polling.
- **Every `flow_logs` query has a time window** (the list a period, at most 30 days; a record ±5
  minutes around the time in its ULID), so it touches the partitions it needs. Example:
  `FlowLogInspector`.
- **The builder is a separate Inertia app**: reach it with a plain `<a>` or `Inertia::location()`
  to the named route `builder.flows.show`, never a `<Link>` or a redirect. A related record
  created from inside a form (a flow's new group) uses its own `*-inline` route that redirects
  back with the new id in the flash.

## Example

`ContactGroupController`, `ContactGroupService`, `ContactGroupRequest`,
`DestroyContactGroupsRequest`, `resources/js/pages/Console/ContactGroups/*`,
`tests/Feature/Console/ContactGroupsConsoleTest.php`. With filters, grouping, guards and an
assistant-owned policy: `FlowController`, `FlowDraftService`, `resources/js/pages/Console/Flows/*`.
With a confirmed irreversible step, a live reach count and a localized field: `BroadcastController`,
`BroadcastService`, `resources/js/pages/Console/Broadcasts/*`. A form with a tree of rules whose choices come from the domain: `ContactSegmentController`,
`ContactSegmentService`, `ContactSegmentRequest`, `resources/js/pages/Console/ContactSegments/*`. A card with narrow writes (replace a set, no create or delete): `ContactController`,
`AssistantContactService`, `ContactCard`, `resources/js/pages/Console/Contacts/*`.

## Rationale

About thirty screens move the same way. One worked pattern, reviewed once, keeps tenant isolation,
authorization and list behaviour identical across them, and makes a screen that differs stand out
in review.
