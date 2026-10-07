# UI foundation: Core's operator UI on Inertia

Depth: deep — three to four months of work, an irreversible exit from Filament, and a public
contract for extensions; every decision is taken with its trade-off visible and the plan went
through an independent failure hunt.

## Idea

Step 5 of the product roadmap, as first stated: "UI foundation for extenders — one token source
shared by the operator-facing surfaces and the small set of primitives an extension actually
composes against."

Widened by the owner on 2026-10-06: "drop Filament and rewrite everything on Inertia" — Filament
stays only in the closed SaaS package (its operator panel); everything Core ships today in its
admin and assistant panels moves to Inertia + Vue 3 + Tailwind 4, on one design system shared
with the builder.

## Goal and problem

- Why, in the owner's order of answers (all four were chosen):
  1. Own product UX. The assistant console is what SaaS customers and self-hosters see; it has
     to look and behave like a product, not like an admin generator.
  2. One stack with the builder. Vue everywhere: one design system, one component set, the
     builder and the console as one application, no Livewire.
  3. Extensions for Solutions. A Solution author adds screens to the console against Core's own
     contract, not against Filament's API, which breaks on every Filament major.
  4. Dependency and limits. Filament majors force migrations, the theme is fought with ~335
     lines of overrides, Livewire limits realtime and complex forms.
- Who is worse off without it: the operator using the console (an admin-generator UX), the
  Solution author (nothing stable to compose against), the maintainer (two front-end stacks,
  three token sets, Filament upgrades).
- Success, as the owner chose it: **parity, then Filament removed.** Every screen that exists
  today exists on Inertia with the same level of function; `filament/filament`, its plugins and
  `livewire/livewire` are gone from Core's `composer.json`; a Solution can add a screen to the
  console. UX improvements are taken where they are cheap, not as a goal.

## Terms

- **The kit** (`@fapost/ui`) — the front-end component set every operator screen is built from,
  in three layers: tokens (CSS custom properties for colours in light and dark, fonts, radii,
  shadows); base components (shadcn-vue copied into the kit and coloured by the tokens — button,
  input, select, dialog, tabs, badge, table, toast, command palette, dropdown); and FaPost
  components shadcn does not have — the server-driven `DataTable`, the form field bound to
  Laravel validation errors, `LocalizedTextarea`, confirmation dialog, page header, empty state,
  status badge, the `AppShell` (navigation, assistant switcher, language, breadcrumbs), the
  `useLiveUpdates` composable, chart wrappers. Not in the kit: the screens themselves (Core's
  application code built from it), the builder's own components, and the PHP side of the
  extension contract.

## What exists today

Facts gathered 2026-10-06; evidence paths are in the task notes of this session's research.

- Two Filament 5 panels: `admin` (default, top navigation) and `assistant` (SPA, the
  `Assistant` model as Filament's "tenant", URLs `/assistant/{assistant_id}/…`).
- 14 resources, 38 resource pages, 6 custom pages, the default dashboard with 2 widgets, 1
  relation manager, 2 login pages — about 46 routable screens; ~8.3k lines of PHP under
  `app/Filament`, ~1.1k lines of custom Blade, 26 test files touching the panels.
- Complexity: L — Media library, assistant settings, conversation inbox; M/L — contacts; M — 14
  screens; S — 4 screens.
- Auth surface is small: login only — no password reset, registration, e-mail verification,
  profile or MFA.
- Filament reaches outside `app/Filament`: `User` implements `FilamentUser` and `HasTenants`;
  `CurrentAssistant` resolves from `Filament::getTenant()` and is consumed by runtime services
  and jobs; guests are redirected to `filament.admin.auth.login`; `ActivationController`,
  `SetLocale` (the language-switcher plugin's cookie), `InAppStaffNotifier`, the Dockerfile and
  CI build order (the theme imports CSS from `vendor/`).
- Already in place: the builder runs on Inertia v3 + Vue 3 + Tailwind 4 and shares the `web`
  session guard; Media REST endpoints exist; `HandleInertiaRequests` shares no user,
  permissions or flash messages yet.
- Gaps found on the way: Broadcasts and ContactSegments have no policy (visible to any signed-in
  user); builder routes check no policy (`SaveDraftRequest::authorize()` returns true).
- No extension can depend on Core's Filament today: no Foundation contract exposes the panels,
  discovery is limited to `app_path`, the stability policy excludes `App\…` and the compiled
  front end. The only promise is ADR-06's capability row "Filament resources: Solution ✓",
  unbuilt.
- Other specs plan screens on Filament: tenant-quotas, mcp-server, audit-log,
  rag-knowledge-bases, forms-data-collection, solution-activation-lifecycle,
  operator-insights, builder-versioning. Only tenant-quotas has filed work touching the panel
  (`quota-contract-assistant-seam`, `support-access-contract`).

## Stress test

An independent failure hunt (a fresh agent given only the idea and the chosen options) ran on
2026-10-07; its findings are folded in below, ranked by likelihood times cost.

- Hidden assumptions — "this holds only if …":
  - Parity is a fixed target. It is not: `main` keeps changing what screens must do (record
    limits, read-only tenants), so parity moves while the migration runs.
  - Filament's implicit behaviour is visible in its screens. It is not: policies applied to
    every action including bulk ones, relation-manager scoping, unsaved-change guards, filters
    kept in the session, soft-delete restore — an agent rebuilding a screen copies what it
    shows, not what Filament enforced.
  - The current assistant exists wherever `CurrentAssistant` is read. Only inside a console
    request; queued jobs have no route segment, and a binding that outlives a job carries one
    assistant into the next job on the same worker.
  - Solutions can be installed on the published images. Not while a Solution's Vue needs an npm
    rebuild (D4): a self-hoster on the prebuilt `core`/`web` images has no front-end build.
  - The SaaS package's Filament panel is unaffected. Only if nothing it relies on leaves Core
    unnoticed — Filament interfaces on `User` and `Assistant`, Livewire-aware middleware, Vite
    and Tailwind configuration now owned by Core's new front end.
- Trade-offs:
  - The new UI hidden behind a switch on `main` (no mixed-UI release, no long branch) against
    two UIs living in one codebase until the last pull request, and a switch that must be tested
    both ways.
  - A full extension contract and a separately versioned kit now (a contract for Solutions from
    day one) against guessing every shape before a consumer exists and paying a release for
    nearly every kit change.
- The weakest point: the extension contract for Solution UI is designed in full before any
  Solution exists, and its distribution (published Vue plus an npm rebuild) does not reach
  self-hosters on the prebuilt images. The long-lived epic was the weakest point until the
  build moved onto `main` behind a switch.
- Failure modes — cause, what breaks, the signal:
  1. The epic drifts from `main` — changes made in Filament code on `main` are re-expressed by
     hand on the epic and nothing flags a missed one; the final diff cannot be reviewed; users
     find the regressions. Signal: growing merges into the epic, CI red after each, the parity
     list growing faster than it shrinks, "fixed on main, broken on epic".
  2. The schedule overruns and the freeze becomes a backlog — backend-only features pile up
     untested end to end, the SaaS launch waiting for the read-only tenant slips, someone adds
     "just one" Filament screen. Signal: week 19 with parity under ~70%, Filament classes in new
     pull requests.
  3. Authorization Filament gave for free is lost — missing `authorize()` on update, delete and
     bulk endpoints, unscoped `findOrFail` across assistants. Signal: fewer policy checks than
     routes; a record of one assistant reachable from another's URL.
  4. `CurrentAssistant` is null or stale outside a request — jobs fail, or write under the
     previous job's assistant. Signal: intermittent "assistant not set" in Horizon, data under
     the wrong assistant only under worker load.
  5. The extension contract is guessed and its distribution fails — the first real Solution
     needs a breaking change to a public contract; published Vue goes stale after
     `composer update`; Docker users cannot add a Solution. Signal: "page blank / component not
     found" issues, the first Solution asking to change the contract.
  6. The SaaS package breaks on a Core upgrade — route or path collisions with Core's new
     screens, login redirecting into the wrong UI, Filament upgrades now entirely the SaaS's.
     Signal: SaaS CI red after each Core release.
  7. Two stacks on the same URLs during the strangler — route-name collisions under
     `route:cache`, redirect loops through `/admin/login`, a lost assistant on cross-stack links,
     Inertia 409s. Signal: duplicate names in `route:list`, "logged out after a menu click".
  8. The separate kit package slows every change — a release and a lockfile bump per screen;
     `npm link` resolving a second Vue (broken provide/inject); Tailwind 4 not scanning
     `node_modules` without `@source`, so kit classes vanish from production builds. Signal:
     unstyled components only in CI, weekly 0.x kit releases.
  9. Live updates add load — polling over partitioned flow logs without partition pruning,
     broadcast jobs queued even with the null broadcaster, private channels authorized without
     tenant context in the SaaS. Signal: slow-query logs on log and inbox endpoints, 403s on
     `/broadcasting/auth`.
  10. Uploads regress — Livewire's temporary-upload pipeline replaced by hand: orphaned files,
      wrong-tenant paths, failed large uploads. Signal: storage growing without matching rows.
  11. The builder and the theme drift — Tailwind 4 preflight against 1140 lines of hand-written
      CSS, half-mapped dark variants; whole lang files shared to every page. Signal: builder-only
      visual bugs, raw translation keys, page props over ~100 KB.
  12. Wayfinder's generated files conflict between `main` and the epic, and Solutions' routes
      are missing from them. Signal: recurring conflicts in generated directories, 404s from
      calls that type-checked.
- Other shapes considered, and why this one:
  - Hybrid — the console on Inertia, the admin panel left on Filament. Rejected: success is
    defined as Filament removed from Core.
  - Stay on Filament and theme it hard. Rejected: answers neither the extension contract nor
    one stack with the builder.
  - Cut the most expensive part — ship the console first, the admin panel later. Rejected with
    the single-release decision.

## Scope and non-goals

- In scope: one token source and one component kit for every operator-facing surface (console,
  admin, builder); every screen of both panels rebuilt on Inertia at parity; the replacement of
  Filament's tenancy layer (the current assistant, scoping, access); auth (login, guest
  redirect, language switch); the contract by which a Solution adds a screen; removal of
  Filament and Livewire from Core; the public docs, knowledge and tests that describe the
  panels.
- Not doing: a UX redesign of flows (cheap improvements only); the SaaS package's operator
  panel, which stays on Filament in its own repository; new auth features (password reset,
  MFA); an in-app notifications inbox (not shown today either); the public site's styling unless
  D5 decides otherwise.

## Decisions

- **This spec replaces the original step-5 scope instead of a new spec beside it** — rejected:
  a separate migration spec, because two specs on one design system drift; rejected: dropping
  ui-foundation for a new id, because the product roadmap already points at this one.
- **Visual direction: Warm Minimal tokens, Onest for text, Roboto Condensed for display** —
  stone neutrals, one sage accent (`#5a6e58` light, `#8fa68c` dark), hairline borders; Roboto
  Condensed on page and section headings, navigation group labels, table column headers, large
  metrics and the wordmark. Light and dark values mapped onto shadcn-vue's variables, every
  text pair at AA. Mock-ups: https://claude.ai/artifact/HmFyWwxvYfutcTEZfu6JSw — rejected: DM
  Sans (no Cyrillic, the panels render ru/uk in a fallback font today); Montserrat for body text
  (too wide for dense tables); Roboto alone (no character).
- **Freeze: no new screen is built on Filament in Core.** Other specs keep shipping their
  backend; their UI items wait for the kit and are built on it. Only small edits that in-flight
  tasks already designed land in Filament (`quota-contract-assistant-seam` hiding the Create
  button). — rejected: urgent SaaS-launch screens (read-only tenant banner, support banner) on
  Filament now, because each is built twice and the read-only design is Livewire-specific;
  rejected: no freeze, because every new Filament screen lengthens the migration.
- **Same URLs, screen by screen.** The new shell and navigation come first; a migrated screen
  takes over its existing address (`/admin/…`, `/assistant/{assistant}/…`); menu entries for
  screens not yet migrated lead into Filament until they are. `/admin/login` and the other
  addresses stay, so `ProvisionedTenant.loginUrl`, bookmarks and docs keep working. — rejected:
  a new `/app` prefix switched over at the end, because it runs two applications side by side
  and moves every risk into one cut-over.
- **Built on `main` behind a switch, released visible once.** Every migrated screen is its own
  pull request into `main`; its routes take over only when a configuration switch turns the new
  UI on, and the switch defaults to off, so every release tag still shows Filament. The owner and
  the SaaS turn the new UI on early. The last pull request removes the switch together with
  Filament. A change on `main` lands where both UIs see it, so nothing is re-expressed by hand
  at the end. Changed on 2026-10-07 after the failure hunt — rejected: one epic branch released
  once (first choice; three to four months beside an active `main`, one unreviewable diff at the
  end); rejected: each screen visible in releases as it lands (mixed UI in a release); rejected:
  one epic per phase (a half-migrated release in between anyway).
- **Front-end dependencies agreed (2026-10-07):** shadcn-vue (components copied into the
  repository) with reka-ui, class-variance-authority, clsx and tailwind-merge; lucide-vue-next
  for icons; @tanstack/vue-table as the table engine; shadcn-vue charts (unovis) for charts;
  laravel/wayfinder for typed Laravel routes in TypeScript instead of hard-coded URLs. —
  rejected: Nuxt UI (its own visual language to fight, a package rather than code we own);
  PrimeVue (its own theming system); passing route strings as props (untyped, the builder's
  hard-coded `/builder/flows/{id}` links are the example).
- **The current assistant comes from the route, scoping is explicit.** Console routes carry
  `/assistant/{assistant}`; one middleware authorizes access (404 when denied, as today) and
  sets `CurrentAssistant`; every query filters by assistant explicitly; an architecture test
  requires that middleware on every console route. `CurrentAssistant` stops reading
  `Filament::getTenant()` before any screen moves, because runtime jobs and the flow engine
  consume it. — rejected: a global Eloquent scope (Filament's way), because it also fires
  silently inside jobs and the engine; rejected: the assistant kept in the session, because it
  breaks today's URLs and two assistants in two browser tabs.
- **The full extension contract for Solution UI is in this spec** (owner's choice; the
  recommendation was a minimal page-plus-menu contract): pages, navigation entries, dashboard
  widgets, settings tabs and columns added to Core's tables. Vue code arrives the way builder
  components do (published into `vendor/`, picked up by a Vite glob, then a rebuild — ADR-06,
  D4); registration goes through a Foundation contract. A new ADR supersedes ADR-06's row
  "Filament resources: Solution ✓". Cost accepted: every extension point is designed before any
  Solution consumes it (step 6 is the first), so each is a guess to maintain; see Stress test.
- **Extensions compose against a separate, versioned npm package of the kit** (owner's choice;
  the recommendation was an import alias with no guarantee until 1.0). Core consumes the same
  package, so a Solution and Core render the same components; the package follows semver on its
  own release cycle. A Solution's Vue is compiled inside Core's build, so its imports of
  `@fapost/ui` resolve to Core's copy — one version, one Vue instance, the same tokens; a Solution
  declares the kit as a `peerDependency` with the range it supports and never bundles its own
  copy. The npm package serves the Solution author's development (types, editor support,
  component tests outside Core) and states compatibility. The kit is the default path, not an
  obligation: a Solution built by a third party may carry its own visual style (owner,
  2026-10-07). One limit holds because its CSS is compiled into Core's bundle — a Solution's
  styles stay scoped to its own components (`<style scoped>` or its own class prefix), with no
  global rules, resets or overrides of the kit's classes, so it can look like anything on its own
  pages and cannot restyle Core's or another Solution's. Cost accepted: one more repository and
  release cycle before the first external consumer exists.
- **The builder moves onto the shared tokens, not onto the kit.** Its CSS variables take their
  values from the kit's tokens, its fonts and top bar match the console, dark mode works there
  too; rewriting the builder's own components on the kit is follow-up work outside this spec. —
  rejected: rewriting the builder on the kit here (three to four more weeks, risk on the most
  complex interface); rejected: leaving it alone (the duplicate palette, step 5's original
  problem, stays).
- **The kit package lives like foundation and support:** its own repository `fapost-lab/ui`,
  Apache-2.0, published to the public npm registry as `@fapost/ui` from a tag; locally
  `packages/fapost-ui` is linked into `node_modules` the way `composer dev:link` links PHP
  packages. **It is extracted at the end, not at the start:** during the migration the kit grows
  in a folder of Core behind the `@fapost/ui` import alias, changed without releases; once parity
  is reached and the set has settled, it moves to its own repository and ships `0.1.0`, and no
  import changes. Changed on 2026-10-07 after the failure hunt (a release per kit change, a
  second Vue under `npm link`, Tailwind not scanning `node_modules`). — rejected: a folder in Core published by Core's CI (the kit's version welded to
  Core's); rejected: GitHub Packages (installing needs a token even for public packages, a
  barrier for self-hosters and outside authors).
- **Live updates switch by configuration: polling by default, WebSockets when configured —
  self-hosted Core included.** The server always raises Laravel broadcast events for what a
  screen shows live (inbox, sessions, logs); with `BROADCAST_CONNECTION=null`, Core's default,
  they go nowhere. The shared Inertia props tell the front end whether a broadcaster is
  configured; one composable subscribes through Echo when it is and falls back to Inertia
  polling at today's intervals when it is not. Core requires `laravel/reverb` and ships the Echo
  client (`laravel-echo`, `pusher-js`) as a lazily loaded chunk, both dormant until configured;
  the Docker Compose stack gains an optional `reverb` service behind a profile, off by default,
  and the self-hosting docs a "Realtime" page. A self-hoster on the prebuilt images turns it on
  with the profile and a few environment variables; the SaaS turns it on through its own
  configuration and requires nothing itself. The Echo client is not Reverb-specific: it speaks
  the Pusher protocol, so Pusher, Soketi or Ably work too. — rejected: polling only (the owner
  wants WebSockets); rejected: Reverb required to run (a new service every self-hoster must
  run); rejected: `laravel/reverb` required only by the SaaS package (owner, 2026-10-07: a
  self-hosted Core must be able to run it, and an installation on the prebuilt images cannot add
  a Composer package); rejected: a separate `fapost/realtime` package (broadcasting is built into
  Laravel and the server is already its own package — it would wrap configuration in another
  release cycle).
- **The authorization gaps are fixed now, on `main`, as their own task** — Broadcasts and
  ContactSegments without a policy, builder routes without a policy check. They are a hole in
  the current release; policies live in the domains, so the migrated screens simply use them.
  — rejected: fixing them screen by screen inside the migration (the hole stays open until the
  epic is released).
- **D5 closed: the public site keeps its own palette.** Marketing changes on its own rhythm and
  must not be able to break the console. — rejected: one token source for every surface;
  rejected: shared fonts and wordmark only (no request for it, and it still couples the two).
- **What Filament enforced implicitly becomes an explicit, tested rule.** Every console and
  admin controller action authorizes through a policy, bulk actions included, and every record
  lookup is scoped to the current assistant; an architecture test fails a controller action
  without an authorization call. Answers failure mode 3.
- **The current assistant never travels by ambient state into a job.** A job receives the
  assistant id in its payload and sets `CurrentAssistant` for its own run; the binding is
  request- or job-scoped and reset afterwards, per the worker-safety convention. Answers failure
  mode 4.
- **The SaaS package's CI runs against Core's `main` with the new UI switched on**, so a
  collision with its own Filament panel (routes, login redirect, middleware) shows in its CI,
  not in a deployment. Answers failure mode 6.
- Superseded from the original scope: "the builder and the Filament panel share tokens at the
  CSS custom property level only" and "the shared layer is tokens plus a small primitive set,
  not a component library" — with one Vue stack the component kit is Core's own UI, not an
  extra library; what is published to extensions is a separate decision (below).

## Open questions

- D4, now sharper: how a Solution's UI reaches an installation on the prebuilt `core`/`web`
  images, where nobody runs an npm build. Rebuild in a derived image, or Solutions shipping
  prebuilt ES-module bundles loaded at runtime — the second changes ADR-06's build-time-only
  stance. Decide before the extension contract is published, not before the migration starts.
- The version story of `@fapost/ui` and of the extension contract — what an installed Solution is
  promised when Core restyles or reshapes a table. It cannot be stated before something outside
  Core depends on them.
- Which extension-point shapes survive contact with a real Solution — answered by step 6
  (`first-solution`); until then every point is marked experimental in the docs.

## Assumptions left untested

Reversible choices taken without asking, listed so they can be overturned:

- The kit grows in `resources/js/ui` until it is extracted.
- Login becomes an Inertia page at the same `/admin/login`; the guest redirect points at the new
  route name; there is still no password reset or MFA.
- The language switcher is Core's own and keeps reading the cookie today's plugin writes, so a
  user's chosen locale survives the switch.
- Filament's implicit global search in the admin panel is matched by a ⌘K command palette over
  the same four resources.
- Wayfinder's generated route files are built, not committed, so `main` and feature branches do
  not conflict on them (failure mode 12).
- Each page receives only the translation namespaces it uses, not whole lang files (failure
  mode 11).
- Live screens over partitioned flow logs always query a bounded time window so Postgres prunes
  partitions, polled or pushed (failure mode 9).
- The in-app notifications inbox stays out of scope: notifications are stored today but never
  shown, and parity does not require showing them.
- While the switch defaults to off, a migrated screen changes nothing a staff user of a release
  sees, so `docs/site` is updated once, at the cut-over, instead of with every screen — the
  published-docs convention is met at the point the behaviour becomes visible.
- The dashboard chart's bucketing logic moves into a query service, so its eight tests survive
  the move.
