---
id: adr-20261015-ui-kit-package-shape
type: adr
status: accepted
date: 2026-10-15
domains: []
paths:
  - package.json
  - vite.config.ts
  - vitest.config.ts
  - tsconfig.json
  - tools/dev-link-npm.mjs
  - resources/css/ui.css
  - "resources/js/console/shared/**"
  - docker/compose.dev.yaml
summary: "The console kit is the npm package @fapost/ui (fapost-lab/ui): source distribution, shell and live updates stay in Core, peers vue/inertia/reka-ui/vue-sonner/tailwindcss, KIT_MESSAGES for host strings, 0.x semver rule, release by tag (token or Trusted Publishing; 0.1.0 published by hand, no provenance), npm ci with the kit linked"
reviewed_at: 2026-10-15
---
# The console kit is the npm package @fapost/ui, shipped as source, without the shell, on a 0.x semver rule

## Context

The console kit (components, design tokens, Tailwind theme, fonts) lived in Core as `resources/js/ui` behind a
`@fapost/ui` alias. Solutions render against the same kit at run time through Core's import map
(`adr-20261014-solution-console-ui`), and their authors need something to build and type-check against, so the kit has
to be released on its own. Every consumer is Vite with `@vitejs/plugin-vue` and Tailwind 4. The console shell and the
live-updates helpers read the shape of Core's shared props (`HandleInertiaRequests`, `ApplicationRail`, the
broadcaster), which changes together with Core's PHP. Spec `ui-foundation`, task `kit-extract-package`.

## Decision

- **Package.** `@fapost/ui` on npm, repository `fapost-lab/ui`, Apache-2.0. Core depends on it with `^0.1.0`; locally
  the checkout `packages/fapost-ui` is linked over `node_modules/@fapost/ui` by `tools/dev-link-npm.mjs` (`npm run
  dev:link`, also `postinstall`, a no-op without `packages/`), the npm mirror of `composer dev:link`.
- **Source distribution.** The package ships `.vue` and `.ts` sources (ESM), no `dist/`. Its `exports` map is the whole
  public API: `components/*` (index only, no deep paths), `lib/utils`, `lib/relative-time`, `lib/messages`, four
  stylesheets (`styles.css`, `theme.css`, `tokens.css`, `fonts.css`) and `surface.json`. `styles.css` declares `@source`
  on the kit's components, so the host's Tailwind finds their classes wherever the package is installed. A consumer
  sets `optimizeDeps.exclude: ['@fapost/ui']` (Vite) and `test.server.deps.inline: ['@fapost/ui']` (Vitest).
- **The shell stays in Core.** `resources/js/console/shell`, `resources/js/console/lib` (live updates) and
  `resources/js/shared/theme` are Core's code; the kit imports nothing from Core, never reads page props, and takes
  every label through props or `KIT_MESSAGES`.
- **Peers.** `vue`, `@inertiajs/vue3`, `reka-ui`, `vue-sonner`, `tailwindcss` are peer dependencies: each must exist
  once on a page (shared through the import map, provide/inject contexts, the toast store, one Tailwind). Everything
  else is a dependency of the kit (icons, `@vueuse/core`, cva/clsx/tailwind-merge, Fontsource). Core lists
  `resolve.dedupe` for the peers plus `@lucide/vue` and `@vueuse/core` in Vite and Vitest, and `paths` for the four
  runtime peers in `tsconfig.json`, so a linked checkout with its own `node_modules` still gives one Vue.
- **Host strings.** The kit's own strings (DataTable labels, the close button) are `KitMessages` in
  `@fapost/ui/lib/messages`, with English defaults; the host provides `KIT_MESSAGES` (Core's `AppShell`, from
  `translations.console`). A new kit string is an additive change of that contract.
- **Surface.** The package's `surface.json` lists the subpaths a Solution may import; the package's tests keep it equal
  to `exports` and `src/index.ts` and keep export names unique. Core keeps a copy, `resources/js/console/shared/ui-surface.json`,
  because PHP reads it at run time in an image without `node_modules`; `ui-surface.test.ts` compares the copy with the
  installed package's `surface.json` and with the `ui` entry.
- **Versions.** Semver with a rule for `0.x`: a breaking change raises the minor (`0.2.0`), additions and fixes raise
  the patch. Breaking: a subpath, export or token removed or renamed; a prop, event or slot removed or changed in
  meaning; a peer major changed. Not breaking: new components, props or tokens; a visual change to match the mockups;
  internal Tailwind classes. So a Solution's `^0.1.0` (npm and `composer/semver` read it as `>=0.1.0 <0.2.0`) takes new
  components and never a break. `1.0.0` comes after `first-solution` has lived on the kit.
- **Release.** A `v*` tag on the package's `main` runs `release.yml`: the CI checks, tag equals `version`, `npm publish
  --provenance`. The workflow publishes with an `NPM_TOKEN` secret or, once a trusted publisher is configured for it on
  npmjs.com, through OIDC with no token. `0.1.0` was published by hand by the owner and so carries no provenance: npm
  refused the granular token in CI because publishing with it would bypass the account's two-factor requirement, and a
  trusted publisher can only be configured on a package that already exists. Configuring Trusted Publishing for
  `release.yml` (and dropping any token) is the owner's step after `0.1.0`; until it is done no release is guaranteed
  to have provenance.
- **Local install trap.** With `packages/fapost-ui` linked, `npm install` in Core treats the link target as part of
  its tree and prunes the checkout's `node_modules`; Core installs with `npm ci` (the `vite` service of
  `docker/compose.dev.yaml` and `composer setup` do), and `dev-link-npm.mjs` warns when a linked checkout has no
  `node_modules/vue`.

## Alternatives

- **Shell in the package as an "internal" subpath** — no import changes, but Core's shared-props contract would live
  in another repository, every shell change would be a kit release, and semver would cover what nobody promised.
  Rejected.
- **A Core alias `@fapost/ui/shell` onto `resources/js/console/shell`** — a package-looking specifier that leads into
  Core; confuses readers, TS paths and Solution authors. Rejected.
- **A built `dist/` (Vite library mode with `.d.ts`)** — no `optimizeDeps`/`deps.inline` for consumers, but a build per
  link (`--watch`), sourcemaps for debugging and a separate check that Tailwind sees classes in compiled JS. Every
  consumer is Vite + plugin-vue, so nothing is gained; it can come later without changing subpaths.
- **`npm link`, a `file:` dependency, npm workspaces** — a global link is lost on every `npm ci` and brings a second
  Vue; `file:` writes a local path into `package.json` and the lock and breaks the production build; workspaces make
  the lock depend on whether `packages/` exists. Rejected for the relative symlink of `dev-link-npm.mjs`.
- **Changesets or semantic-release** — new tools and process for rare releases; the CHANGELOG is kept by hand, as in
  `fapost/foundation`.

## Consequences

- Core and the image's `assets` stage get the kit from the public registry with plain `npm ci`; no token, no change to
  CI. The Dockerfile copies `tools/dev-link-npm.mjs` before `npm ci` because `postinstall` runs it.
- `vue-tsc` in Core type-checks the kit's sources too; Core and the package keep the same strict settings and the same
  TypeScript major. A kit bump is its own Core pull request.
- A change to the kit is a release and a bump in Core; between them it is developed through the link.
- A kit release that changes `surface.json` fails Core's `ui-surface.test.ts` until Core's copy and the `ui` entry
  follow.
- The tokens and boundary tests of the kit now run in `fapost-lab/ui`, not in Core.
