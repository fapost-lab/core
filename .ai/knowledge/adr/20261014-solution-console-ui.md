---
id: adr-20261014-solution-console-ui
type: adr
status: accepted
date: 2026-10-14
domains: []
paths:
  - "resources/js/console/**"
  - resources/views/console.blade.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - "app/Http/Shell/**"
  - vite.config.ts
summary: "How a Solution adds to the console: server-side declarations on named Core targets, pages by <id>::Path, a prebuilt dist/ on Core's shared Vue, Inertia and kit through an import map; supersedes ADR-06 for Solutions"
reviewed_at: 2026-10-15
---
# A Solution adds to the console through server-side declarations and a prebuilt bundle on Core's shared modules

## Context

Core's operator UI is leaving Filament for Inertia + Vue on one kit (`@fapost/ui`), spec `ui-foundation`. The only
promise Core ever made to a Solution about the panels was ADR-06's row "Filament resources: Solution ✓", never built,
together with ADR-06's rule that Solution Vue is published into `vendor/`, picked up by a Vite glob and requires
`npm run build` on every install. Neither survives the migration:

- Filament leaves Core, so a contract on Filament's API has nothing to stand on.
- The published images are prebuilt; a self-hoster adds a Solution through the composer overlay and a derived image
  with no Node. A rebuild per install also gives a Solution's own npm dependencies nowhere to live, and lets a
  Solution's build error break Core's build.

The owner decided in `ui-foundation` and `first-solution` (2026-10-07) that a Solution is an independent application
on top of Core, that it adds an application to the app switcher plus contextual actions, a shell slot, dashboard
widgets, settings tabs and table columns, that its pages are referenced by name, and that its UI ships prebuilt with
Vue, Inertia and the kit shared from Core through an import map. The Solution manifest
(`adr-20261010-solution-manifest-is-data-in-composer-json`) made the manifest data in the package's `composer.json`
(`extra.fapost`, immutable `id`) and left `ui` out of schema 1. The SaaS package sells Solutions as add-ons (`packages/fapost-saas`,
`adr-20261010-solutions-sold-as-add-ons`): losing an entitlement deactivates a Solution in Core, so its UI must
disappear without a rebuild or restart. This ADR fixes the shapes those decisions left open. Decided with the owner
at the T3 gate of task `solution-ui-adr` on 2026-10-14.

## Decision

**Declarations are server-side PHP data.** A Solution registers its console UI from its provider's `boot()` through
a Foundation registry, `ConsoleExtensionsInterface`, resolved from the container (the `LimitRegistryInterface`
pattern); Core closes the registry after boot. Core binds every declaration to the `id` of the Solution whose provider
is booting, so a Solution cannot declare for another. Declarations are held per process, not per tenant; on each
request Core keeps only those whose Solution is active for the current tenant, whose `ability` the user has, and —
for per-assistant points — where an assistant is selected, and passes them to the client in shared props. Hiding in
the browser is never the access check.

**Every declaration targets a named point Core lists in Foundation** (constants such as `ConsoleTarget::ContactView`).
The list is closed and grows in Foundation minors; an unknown target is a registration error. Labels are translation
keys in the Solution's namespace (`feedback::console.menu.board`) translated by Core on the server; icons are lucide
names; Core's elements come first among items with the same `order`, and Solutions with the same `order` follow by
`id`. Every component Core mounts inside its
own screens is wrapped so that a load failure, timeout or thrown error hides that element and leaves the screen
working. The points, in priority order:

1. **Application** — one per Solution, its id is the Solution `id`: label, icon, `perAssistant`, entry route, menu
   items (label, route name, icon, ability, group), ability. It fills the app switcher's server-side source of
   applications; Core's own menu never receives Solution entries.
2. **Contextual action** — on a target such as `contact.view`, `contact.row`, `conversation.view`; `link` to a
   Solution page with the record id, or `dialog` mounting a Solution component with `{recordId, assistantId}`.
3. **Shell element** — in slot `floating` or `header`; its chunk loads lazily after the page renders.
4. **Dashboard widget** — on `console` or `admin`, with a size and order; it fetches its own data from the
   Solution's routes, never through Core's dashboard request.
5. **Settings tab** — on `assistant` or `tenant` settings; a self-contained form with its own routes and storage,
   keyed `<id>.<key>`.
6. **Table column** — on `contacts` or `conversations`; values come from a `ColumnValuesInterface` called once per
   page with the row ids, rendered with a kit cell type (text, badge, number, date, status); no sorting, search or
   Solution component in a cell in the first version.

**Solution routes sit behind Core's `solution` middleware group** — web, auth, tenant, active staff user, the Solution
active for the tenant, the application's ability — under `/apps/{id}/…`, or `/assistant/{tenant}/apps/{id}/…` with
the console's assistant middleware for a per-assistant application. Route names are `solutions.<id>.*`. A
deactivated Solution answers 404 on every page. A response rendering a page outside its own namespace is an error.

**Pages and components are referenced by name, `<id>::<Path>`** (`feedback::Board/Index`); Core's own pages keep
names without a namespace. Core applies `AppShell` as the layout of every `::` page and selects the rail's current
application from the route. The console's page resolver loads a `::` name from the map of active Solutions with
`import()` and attaches its CSS before mounting; a name missing from the map renders Inertia's error page inside the
shell. Inertia's asset `version` includes the set of Solutions active for the tenant and their bundle hashes, so
activation, deactivation or an update forces a full reload on the next visit.

**The UI ships prebuilt.** The author builds `dist/` with FaPost's Vite preset: hashed ES modules and CSS plus
`dist/fapost-ui.json` (`schema`, `solution`, the `kit`, `vue` and `inertia` ranges it was built against, `pages`,
`components`, `css`). The manifest gains an optional field `extra.fapost.ui` pointing at that file; the ranges and the
page map live only there. `platform:update` validates it (file present, schema supported, paths exist, every
component named in PHP declarations is in the map) and copies `dist/` to `public/solutions/<id>/<hash>/`, served as
immutable. Core builds three extra Vite entries with their full export signature — `vue`, `@inertiajs/vue3` and the
whole public surface of `@fapost/ui`, not tree-shaken — whose shared chunks Core's own app imports, so there is one
instance of each. The console's root view renders an inline import map mapping those three specifiers to Core's
entries, before `@vite`, with the page map of the tenant's active Solutions beside it. The builder gets no import map.
Code is same-origin only; there is no CSP today, and when one arrives the inline import map and theme script take a
nonce. There is no SSR for Solution pages.

**Same origin means full trust.** A Solution's JavaScript runs with the signed-in user's session and can call any
Core endpoint as that user and read the screen it is mounted in. That adds no trust boundary: its PHP already runs
with the application's full rights, and installing it is the deploy-time decision of whoever builds the image.
Authorization therefore stays on the server, secrets stay out of props, and a mounted component receives no more
than the ids above. This model holds only for code an operator installs; UI uploaded into a running system is the
sandbox question of `ecosystem-distribution`. Plugins ship no Vue.

**Stability.** The contract is Foundation (declarations and targets), the `fapost-ui.json` schema and the naming rule;
its version is Foundation's, gated by Composer. Every point is `@experimental` in PHPDoc and in the published docs
until `first-solution` has exercised it, and may change in a Foundation minor with a changelog entry. `@fapost/ui` is a
run-time interface with strict semver from `0.1.0`, and an installation serves exactly one kit. A Solution's kit range
is checked in PHP with `composer/semver`, so ranges use the syntax it understands (`^`, `~`, `>=,<`); Vue and Inertia
are checked by major. This is an npm range Composer never sees, so it is not the run-time check of the Foundation
constraint that `adr-20261010-solution-manifest-is-data-in-composer-json` rejects; that constraint stays gated by
Composer alone. `platform:update` warns about a mismatch and the activation screen refuses activation with the
reason. A Solution already active when a Core upgrade makes its kit range fail keeps its activation, data, flows and
PHP, but its UI is not served and the switcher marks it as needing an update.

### Boundary

```
Solution console UI (ConsoleExtensionsInterface, fapost-ui.json, <id>::<Name>)

MAY:      register one application, context actions, shell elements, dashboard widgets,
          settings tabs, table columns against targets Core lists in Foundation;
          ship its own npm dependencies inside dist/; style its own components
MUST:     register from boot(); name every page/component <own id>::<Name>; put routes
          behind Core's `solution` group; authorize every route with its own policies;
          externalize vue, @inertiajs/vue3, @fapost/ui; declare the kit range it was built against
MUST NOT: bundle its own Vue/Inertia/kit; add global CSS or override kit classes;
          load code from another origin; rely on hiding in the browser for access;
          render a component of another Solution or of Core by import path
Why:      one Vue instance and one kit are what make prebuilt bundles work without a
          rebuild; the server is the only place activation, entitlement and permissions are known.
```

### What this supersedes in ADR-06

- The row "Filament resources: Solution ✓" becomes "Console UI through the extension contract (prebuilt): Solution ✓,
  Plugin ✗".
- The section "Solution may ship Vue components (with a rebuild)", the `*Config.vue` / `*Preview.vue` naming
  convention, and the consequences "every Solution requires `npm run build`" and "`vendor:publish` for JS".
- The rejected alternative "Runtime dynamic import (B2)" is now the chosen path; its objections (CSP, isolation) are
  answered above.
- The rows "Custom Vue config component", "Custom preview renderer" and "Custom field renderer" become ✗ for
  Solutions: a Solution registers action handlers, not node types (spec `solution-activation-lifecycle`), configured in
  the `call` node through schema. `resources/js/builder/utils/vendorComponents.ts` is removed by the extension
  contract task.
- The row "NodeHandler: Solution ✓" narrows: a Solution registers action handlers reached through the `call` node,
  not node types.
- ADR-06's Plugin rules stand.

## Alternatives

- **Filament resources in Solutions** — Filament leaves Core; a contract on its API would break on every Filament major.
- **Build-time integration (ADR-06):** Vue in `vendor/`, a Vite glob, a rebuild per install — every install needs Node
  and a build, a Solution's npm dependencies have nowhere to live, a Solution's build error breaks Core's.
- **Client-side registration** (the bundle registers its menu and actions when loaded) — every Solution's bundle would
  load on every page to build the menu, a deactivated or unpaid Solution would still reach the browser, and access
  would be checked in the client.
- **Declarations in `composer.json`** — labels need translation and visibility depends on permissions, which JSON does
  not express; a second source beside PHP.
- **Iframes** — no isolation without a separate origin (a second deployment, sign-in, CORS); no shared kit or tokens;
  dialogs, scrolling, height, theme and Inertia navigation each solved across the boundary.
- **Module Federation or single-spa** — a runtime library and its versioning on top of Vite, for what the browser's
  import maps already do.
- **Web Components** — the kit is Vue; props, events, Inertia navigation and provide/inject are lost through a custom
  element.
- **Solution entries in Core's menu** — rejected by the owner in `ui-foundation`: ten Solutions make a long mixed list.
- **Several kit versions served side by side** — two token trees and two provide/inject instances.
- **Serving `dist/` through a PHP route instead of copying it** — no publish step, but PHP-FPM serves static files.
- **A Solution whose kit fails after an upgrade is deactivated** — a front-end mismatch would stop the tenant's flows.
- **The builder on the import map too** — another extension point with no consumer, since Solutions add no node types.

## Consequences

- The `ui-foundation` task `extension contract` builds the Foundation `Console` namespace, Core's registry and
  per-request filter, the props, the client renderers of the six points, the `solution` route group and the fixture
  Solution — and also the run-time half of delivery: the `::` resolver, the import map, the shared Vite entries,
  `dist/` publication in `platform:update` and the Inertia version; the fixture carries a committed built `dist/`. It
  adds `extra.fapost.ui` to the manifest schema as an additive Foundation minor, removes `vendorComponents.ts`,
  publishes an experimental "Console UI" page on docs.fapost.in, and updates `docs/site/extending/stability-policy.mdx`:
  experimental extension points are the stated exception to "no break within a major", and `@fapost/ui`, the
  `fapost-ui.json` schema and `<id>::<Name>` names become covered surface, unlike the rest of the compiled front end.
- `first-solution` phase 0 keeps the author half: the npm preset, the extracted `@fapost/ui`, and the kit-range check
  on activation, which adds `composer/semver` to Core's `require` (approved by the owner on 2026-10-14).
- The app switcher's source of applications accepts `{id, label, icon, href, perAssistant, visible}`; pins store the
  application id, which is the Solution `id`, so the manifest's rule that an `id` never changes now also protects
  page names, URLs, asset paths and pins.
- Every kit change after `0.1.0` is a change to a run-time interface installed Solutions depend on.
- How long an old kit major stays served after 1.0 is left open (spec `ui-foundation`, the version-story fog item).

## Amendments

**2026-10-14, extension contract (approved at its T3 gate).** Two details are settled more precisely than above:

- Targets are enums in Foundation (`Fapost\Foundation\Console\Enums`), one per kind of point, not string constants;
  an unknown target cannot be expressed at all, so "a registration error" above is a type error in practice.
- A `::` name missing from the map, or a bundle that fails to load, renders Core's `Errors/SolutionUnavailable` page
  inside the shell rather than Inertia's generic error page.

**2026-10-15, assistant route parameter (remove-filament PR 3).** The assistant's route parameter is now
`{assistant}`, not `{tenant}`: a Solution's per-assistant routes live under `/assistant/{assistant}/apps/{id}/…`, and
`ResolveCurrentAssistant` reads `{assistant}` (its default) in the `solution.assistant` group.

**2026-10-15, Filament left Core (remove-filament).** The migration the Context describes as under way is
complete: Core has no Filament or Livewire, and the Inertia + Vue console is its only operator UI. The decision is
unchanged; "is leaving" above now reads "has left".
