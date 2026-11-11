# Roadmap — Extension installer

Destination: a self-hosted operator installs a product from the marketplace, or upgrades FaPost
itself, from the admin — with no developer, with Core holding no rights on the host, and with a
manual path that works when no agent is present.

## Phase 1 — Ready for many Solutions

Goal: Core can carry many installed Solutions and apply their schema safely, before anything installs
them for it. Done when: a benchmark with a large set of fixture Solutions stays inside the boot budget,
and a fixture Solution's migration runs in exactly the tenants that activated it.

- [ ] Compiled Solution registry — manifests validated once at install time into one cached file,
      lazy providers, `boot()` side effects declared in the manifest, a boot-time budget held by a
      benchmark in CI
- [ ] Solution migrations on activation — expand-only, applied in a tenant's schema when it activates
      the Solution and on upgrade only where activated; lifts the `registerMigrations` ban with an ADR

## Phase 2 — Upgrade FaPost from the admin

Goal: the first thing the agent does is upgrade Core, which is worth having without any marketplace.
Done when: an operator presses "upgrade" in the admin of a compose installation with the agent and ends
up on the new version; without the agent, the same screen shows the manual path.

- [ ] Ticket contract `ticket.v1` — schema, status, golden examples in `contracts/installer/`, tested
      on both sides (after: Phase 1 of `fapost-agent` agrees the schema — one contract, two readers)
- [ ] Upgrade screen — the admin writes an upgrade ticket, shows status and progress, switches
      maintenance mode, explains rollback limits (after: Ticket contract — the screen writes it)
- [ ] Manual path — with no agent answering, the admin shows the overlay and the command, and hands a
      waiting ticket over after a timeout (after: Upgrade screen — same screen, the other branch)

## Phase 3 — Install from the catalogue

Goal: products reach an installation without a developer. Done when: an operator finds a free
Solution in the catalogue, installs it, and activates it for a tenant.

- [ ] Catalogue screen — the marketplace's catalogue in the admin, filtered by this installation's
      Core, Foundation, kit and Vue versions (after: `marketplace` Phase 1 — the catalogue API)
- [ ] Install and remove a Solution — the same ticket with a package set, compatibility checked
      before it is written, a Core upgrade blocked by an installed product named with an explicit
      "deactivate and upgrade" (after: Upgrade screen — the ticket flow exists)
- [ ] Flow templates from the catalogue — imported as data, no agent and no rebuild (after: Catalogue
      screen — where they are found)

## Phase 4 — Licences

Goal: paid and limited products work on self-hosted installations. Done when: a licence key entered in
the admin lets the agent download exactly the versions the licence covers, and an expired one leaves
the installation running with a notice.

- [ ] Installation identity and licence keys — the installation id, keys stored and handed to the
      agent, dev/staging keys, self-service re-binding (after: `marketplace` licence API)
- [ ] Update check — versions, notices for expired licences and revoked versions, a rollback prompt;
      no tenant data, switchable off (after: Installation identity — it is sent with the check)
- [ ] Offline licence file — signed file uploaded in the admin for air-gapped installations

## Phase 5 — Other product types

- [ ] fog: zip plugins — their own channel on a shared volume or baked into the image by the agent;
      cannot be stated until an agent build's real duration is known (after: Phase 2)
- [ ] fog: SaaS purchases — how a tenant's purchase in the marketplace becomes an activation; the
      shell's side and the marketplace's per-tenant licence are both unsettled
- [ ] fog: the version story of the kit (`@fapost/ui`) and the extension contract — when each reaches
      1.0 and how a product states the range it builds against; carried over from
      `ecosystem-distribution` (and before it `ui-foundation`), the `0.x` rule is in ADR
      `20261015-ui-kit-package-shape`, 1.0 waits for a Solution living on the kit (after:
      `first-solution` — the first real consumer). Compatibility checks in Phase 3 read these ranges

## Waves

1. Compiled Solution registry; Solution migrations on activation; Ticket contract `ticket.v1`
2. Upgrade screen
3. Manual path; Catalogue screen
4. Install and remove a Solution; Flow templates from the catalogue; Installation identity and licence keys; Offline licence file
5. Update check

<!--
Rules (jig-idea §8):
- An item is a finished slice that makes the product noticeably better, never a layer.
- A dependency without a one-line reason is not a dependency.
- A `task-id` appears when the item's task is filed; `[x]` is set when that task is closed.
- `fog:` items are not split or sized; they become real items once the fog lifts.
- No dates, no point estimates: order is the priority.
- `jig spec list` counts checkbox lines only: `[x]` done, a leading backticked task id
  followed by a dash (`—` or `-`) filed, a leading `fog:` fog. Keep waves as a numbered list.
- A wave entry names an item by its title (the text before its first ` — `) or its task id,
  entries separated by `;` — `jig spec plan` reports an entry that names no item or several.
-->
