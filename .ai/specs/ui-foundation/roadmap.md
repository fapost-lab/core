# Roadmap — UI foundation: Core's operator UI on Inertia

Destination: every screen of the admin panel and the assistant console runs on Inertia + Vue on
one kit and one token source shared with the builder; Filament and Livewire are gone from
Core's `composer.json`; a Solution adds screens to the console through a published contract.

## Phase 1 — Foundation

Goal: one migrated screen works end to end behind the switch, on the kit, and nothing in the
runtime depends on Filament any more. Done when: with the switch on, the contact-groups screen
is served by Inertia at its old address inside the new shell, with the switch off nothing
visible changed, and the builder renders from the kit's tokens in light and dark.

- [ ] The current assistant no longer comes from Filament — `CurrentAssistant` is set by a
      route middleware for console requests and from the payload inside jobs, request- or
      job-scoped and reset; the Filament panel keeps working by feeding the same service
- [ ] The kit and its tokens live in Core — shadcn-vue in `resources/js/ui` behind the
      `@fapost/ui` alias, Warm Minimal light and dark, Onest and Roboto Condensed; the builder's
      variables read from the same tokens and its top bar matches the console
- [ ] The new UI switch and shell — a configuration switch (off by default) hands the console
      and admin addresses to Inertia; shell with navigation, assistant switcher, language
      switcher; Inertia login at `/admin/login`; shared props (user, permissions, flash,
      locale, broadcaster); menu entries for unmigrated screens lead into Filament;
      architecture tests for the assistant middleware on console routes and for an
      authorization call in every action; the SaaS package's CI runs with the switch on (after:
      the current assistant — the shell's console routes resolve it; after: the kit — the shell
      is built from it)
- [ ] Pilot: contact groups end to end — the server-side data table (filters, sorting,
      pagination, bulk actions), form, confirmation, toasts; the patterns every later screen
      copies (after: the shell — the screen lives inside it)

- [ ] `close-policy-gaps` — Authorization gaps closed before screens move — broadcasts and contact segments get policies and the builder routes check permissions, so the new UI inherits rules that hold (found while writing this spec)

## Phase 2 — The console at parity

Goal: the assistant console needs no Filament screen. Done when: with the switch on, every
console menu entry is served by Inertia and the console's tests run against the new routes.

- [ ] Flow groups and flows — list with grouping, trigger hints, toggle and delete guards;
      create and edit; links into the builder through typed routes and the builder's way back
      (after: the pilot — copies its table and form patterns)
- [ ] Channels — list, create, edit in a dialog, webhook rotation; the channel form shared with
      the admin side (after: the pilot)
- [ ] Contacts — list with filters, the contact view with grouped attributes, tags and groups
      management (after: the pilot)
- [ ] Contact segments — the rule builder with match all/any and typed conditions, size refresh
      (after: the pilot; after: `close-policy-gaps` — the screen relies on the new policy)
- [ ] Broadcasts — list with progress, send and cancel, the form with live reach and the
      base-language guard (after: the pilot; after: `close-policy-gaps` — the screen relies on
      the new policy)
- [ ] Live updates with sessions and flow logs — the composable that subscribes through Echo
      when a broadcaster is configured and polls otherwise; flow sessions and flow logs on it,
      every log query bounded to a time window (after: the pilot)
- [ ] Realtime for self-hosters — `laravel/reverb` dormant in Core, the Echo client as a lazy
      chunk, an optional `reverb` service behind a Compose profile, a self-hosting "Realtime"
      page (after: live updates — it is what they switch on)
- [ ] The conversation inbox — transcript paging, composer with attachments, take over and
      return to bot, status, read marking after authorization, live through the composable
      (after: live updates — the inbox is its main consumer)
- [ ] Assistant settings — tabs, the commands repeater, localized text fields, the inline
      create-flow dialog (after: the pilot)
- [ ] The assistant dashboard and the translations page shared by both panels (after: the
      pilot)

## Phase 3 — The admin panel at parity

Goal: the admin panel needs no Filament screen. Done when: with the switch on, every admin menu
entry and the dashboard are served by Inertia.

- [ ] Assistants with their channels — list, create, view, edit; the channels section on the
      shared channel form (after: channels in phase 2 — reuses its form)
- [ ] Users and roles — user list with activation and deactivation, role assignment within the
      actor's priority; roles with grouped permission checklists (after: the pilot)
- [ ] The media library — folder tree, upload, rename, move, references, soft delete and
      restore, bulk actions, file view with previews; folder deletion moved out of the page into
      a service (after: the pilot)
- [ ] Tenant settings — languages with their lock rules, runtime and broadcast limits (after:
      the pilot)
- [ ] The admin dashboard and search — stats and the activity chart on a query service, the ⌘K
      palette over assistants, users, roles and media (after: the pilot)

- [ ] `support-access-log-screen` — The support access log — admins see which platform operator entered the tenant as support and when, read from `support_access_entries`; until then the support user's badge in the user list is all the tenant sees (after: Users and roles — it sits next to them)

## Phase 4 — Solutions add screens

Goal: an extension can contribute UI to the console. Done when: a fixture Solution in the test
suite registers an application in the switcher with its own menu and pages, a contextual action
on a Core screen, an element in the shell's slot, a dashboard widget, a settings tab and a table
column, each rendered in the console with pages resolved by name.

- [ ] An ADR that supersedes ADR-06's "Filament resources: Solution ✓" and its build-time-only
      rule — what a Solution may add to the console, pages referenced by name, UI shipped
      prebuilt with the shared modules from Core's import map
- [ ] The app switcher — the rail with the Console, the Admin panel and pinned Solutions, "All
      applications" with search, per-user pinning and order, ⌘K across applications, the
      assistant switcher only where an application works per assistant (after: the shell in phase
      1 — the rail wraps it)
- [ ] The extension contract — a Foundation contract to register an application with its menu
      and pages, contextual actions, shell-slot elements, dashboard widgets, settings tabs and
      table columns; the fixture Solution proving each point; docs marking each point
      experimental (after: the ADR — it fixes the shapes; after: the app switcher — the
      application point lives in it; after: phases 2 and 3 — the other points extend the
      dashboard, settings and data table they built)
- [ ] fog: a mini-app extension point — how a Solution reaches customers in the mini app
- [ ] fog: the version story of the kit and the extension contract

## Phase 5 — Cut-over

Goal: Filament leaves Core. Done when: `filament/filament`, its plugins and `livewire/livewire`
are absent from `composer.json`, the switch is gone, the public docs and knowledge describe the
new UI, and `@fapost/ui` is published.

- [ ] Filament removed — the switch, panel providers, `app/Filament`, the theme and published
      assets, Filament interfaces on `User` and `Assistant`, the vendor-before-node build order
      in the Dockerfile and CI; tests ported to the new routes; the SaaS CI green (after: phases
      2 and 3 — no screen may still need Filament)
- [ ] The docs and knowledge describe the new UI — `docs/site` usage, self-hosting and
      contributing pages, the positioning line; `ARCHITECTURE.md`, the multilingual convention,
      every domain document's admin-UI paths (after: Filament removed — they describe the
      result)
- [ ] The kit extracted — `fapost-lab/ui`, `@fapost/ui` `0.1.0` on npm, Core consuming it with
      no import changed, local linking like `composer dev:link` (after: phases 2 and 3 — the set
      has settled)

## Waves

1. The current assistant without Filament; the kit and its tokens
2. The switch and the shell
3. The pilot
4. The console screens and the admin screens, in parallel; the ADR for Solution UI; the app
   switcher
5. Realtime for self-hosters; the conversation inbox; the extension contract
6. Filament removed; the kit extracted
7. The docs and knowledge

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
-->
