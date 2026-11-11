# Roadmap — fapost-agent

Destination: on a self-hosted installation, a ticket written by the admin becomes a running
installation of the wanted Core and products — safely, with an honest rollback — and the agent keeps
itself up to date.

## Phase 1 — Upgrade Core on compose

Goal: the agent upgrades Core on a single-host compose installation. Done when: a ticket asking for a
newer Core produces a new image, migrations, a switch and a status the admin reads, and a failing
preflight leaves the old version running.

- [ ] Repository, release and signing — the agent's own repository, a static Go binary signed on
      release
- [ ] Ticket pickup and validation — systemd path unit (cron fallback), `ticket.v1` checked against the
      golden examples in Core's `contracts/installer/` (after: Repository, release and signing — where
      the code lives)
- [ ] Build, preflight, switch — derived image, preflight in a separate process, maintenance mode,
      paused queues, migrations, switch, resume, rollback up to migrations, status written back
      (after: Ticket pickup and validation — the input)
- [ ] Installed by `fapost-install` — an explicit optional step (after: Build, preflight, switch —
      something worth installing)

## Phase 2 — Products and licences

Goal: products from the marketplace reach the image. Done when: a ticket with a licensed product builds
with the licence as Composer auth, and a rebuild after the licence expired still succeeds for the
versions it covered.

- [ ] Products in the ticket — package sets from the marketplace repository, licence keys as Composer
      auth (after: `marketplace` Composer repository)
- [ ] Local artifact cache — rebuilds without the marketplace for versions already installed
- [ ] Self-update — `agent.update` ticket, signature checked before replacing the binary

## Phase 3 — Beyond one compose host

- [ ] CI adapter — the ticket becomes a commit to the integrator's overlay, their pipeline builds
- [ ] Offline bundle — signed release-set archive imported on air-gapped installations
- [ ] fog: Kubernetes adapter — a CronJob or an operator; no integrator has asked yet
- [ ] fog: several hosts or replicas on compose — how one switch reaches all of them

## Waves

1. Repository, release and signing
2. Ticket pickup and validation
3. Build, preflight, switch
4. Installed by `fapost-install`; Local artifact cache; Self-update
5. Products in the ticket; CI adapter; Offline bundle

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
