---
id: convention-solution-console-ui
type: convention
status: active
domains:
  - solution
paths:
  - "app/Domains/Solution/Console/**"
  - "app/Domains/Solution/Services/SolutionUi*.php"
  - app/Domains/Solution/Services/PublishedSolutionUi.php
  - "app/Http/Shell/Solution*.php"
  - app/Http/DataTable/SolutionColumns.php
  - "app/Http/Middleware/EnsureSolution*.php"
  - "resources/js/console/solutions/**"
  - "resources/js/console/shared/**"
  - "tests/Fixtures/Solutions/**"
  - config/solutions.php
  - docs/site/extending/console-ui.mdx
summary: "How a Solution adds console UI: the ADR's MAY/MUST/MUST NOT boundary, boot()-only registration and the freeze flag, route groups and route-name ids, the publication allow-list, values() without a timeout"
---
# Solution console UI

## Practice

A Solution adds to the console only through Foundation's `ConsoleExtensionsInterface` and a prebuilt
bundle (`adr-20261014-solution-console-ui`; published as docs.fapost.in `extending/console-ui`).
Everything here is `@experimental` in Foundation 0.15 and may change in a Foundation minor with a
changelog entry. The ADR's boundary, which this convention carries:

```
MAY:      register one application, context actions, shell elements, dashboard widgets,
          settings tabs, table columns against targets Core lists in Foundation;
          ship its own npm dependencies inside dist/; style its own components
MUST:     register from boot(); name every page/component <own id>::<Name>; put routes
          behind Core's `solution` group; authorize every route with its own policies;
          externalize vue, @inertiajs/vue3, @fapost/ui; declare the kit range it was built against
MUST NOT: bundle its own Vue/Inertia/kit; add global CSS or override kit classes;
          load code from another origin; rely on hiding in the browser for access;
          render a component of another Solution or of Core by import path
```

How Core holds it, and what a change to these files must keep:

- **Declarations only from `boot()`.** `SolutionServiceProvider` opens the registry for one Solution id in
  the provider's `booting` callback and closes it in `booted`; a call outside that window, or after
  `freeze()`, throws `InvalidConsoleDeclarationException`. Solutions register in an overridden `boot()`
  after `parent::boot()`, never in `bootSolution()`/`registerExtensions()` — Core binds no
  `CoreRegistrarInterface`, so the base `boot()` returns before calling them. The registry is frozen after
  the application boots unless `solutions.freeze_console_registry` is false; only `phpunit.xml` turns it
  off (tests register fixtures into a booted app). Never turn it off in a deployed config.
- **Names belong to the Solution.** Labels `<id>::…`, components `<id>::<Path>`, routes
  `solutions.<id>.…` — checked by the registry against the booting id. An application is keyed by the
  Solution id, so the ids `console` and `admin` (Core's applications, `CORE_APPLICATIONS`) are refused an
  application; reserving them in Foundation's manifest is task `foundation-reserve-console-app-ids`.
- **Routes only through `routes()`/`assistantRoutes()`.** `SolutionRoutes` adds the panel domain, the prefix
  `apps/<id>` or `assistant/{tenant}/apps/<id>`, the name prefix `solutions.<id>.`, the middleware group
  `solution` or `solution.assistant` (the `admin` stack, plus `ResolveCurrentAssistant:tenant` for the
  second, then `EnsureSolutionActive` and `EnsureSolutionPage`), and `can:<ability>` of the application.
  The Solution a request belongs to is read **from the route name** (`EnsureSolutionActive::solutionOf()`,
  also the rail's current application): a route in these groups without a `solutions.<id>.` name is a
  404. Keep the name, not the URL, as the source of the id.
- **Per request, the server filters.** `SolutionUi::isServed()` (active for the tenant ∧ UI published, when
  the Solution declares one) and the Gate ability decide what reaches the props, the rail, the import map's
  page map and the routes; the client never filters for access. A link action's route takes `record`;
  `contact.row` gets an `hrefTemplate` with `__record__`.
- **`values()` has no timeout.** `SolutionColumns` calls `ColumnValuesInterface::values()` once per page,
  synchronously; PHP cannot interrupt it. A throw or a non-scalar answer empties the column and logs; a slow
  one slows Core's table, which is why the docs ask for one batched query and no remote calls.
- **Publication is an allow-list.** `SolutionUiBundleReader::STATIC_EXTENSIONS` (js, mjs, css, map, woff,
  woff2, ttf, otf, png, jpg, jpeg, gif, webp, avif, ico) plus `fapost-ui.json` are copied; SVG and HTML
  never (script), other visible files stay behind with a warning, hidden paths silently. A file with PHP
  anywhere in its name (`x.php.js` — Apache's multiple extensions), a link leading out of the directory, or
  a bundle at the package root refuses the whole bundle. Widen the list only with a reason a file type
  cannot execute in the browser or on the server.
- **One Vue, one Inertia, one kit.** The import map points `vue`, `@inertiajs/vue3`, `@fapost/ui` and every
  subpath in `resources/js/console/shared/ui-surface.json` at Core's three shared entries, built with
  `preserveEntrySignatures: 'strict'`. A subpath added there is public surface (strict semver from kit
  `0.1.0`); `shell` and `lib/live-updates` stay out. Kit components carry no `<style>`: the import-mapped
  `ui` entry ships no CSS.

## Example

The fixture `tests/Fixtures/Solutions/Showcase` declares every point from `boot()` after
`parent::boot()`, routes of both kinds (`desk` repeats the menu entry's ability as `can:` middleware,
because hiding is no access check), and a hand-written `dist/` whose modules import only the three shared
specifiers; its CSS classes are prefixed `showcase-`.

## Rationale

Prebuilt bundles work without a rebuild only on a single Vue instance and a single kit; activation,
entitlement and permissions are known only on the server, so the browser renders what the server already
filtered. The publication directory is in the web root, so anything a server could execute, or a browser
could run as a document, must never reach it.
