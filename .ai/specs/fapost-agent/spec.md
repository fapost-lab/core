# fapost-agent — the host agent that installs and upgrades a self-hosted FaPost

Depth: deep — it runs as root on the operator's host, rebuilds and switches a production installation,
and is the only piece of the system with rights over it. Written in this repository on 2026-11-12 and
meant to be copied into the agent's own repository when it is created (jig-idea §11); from then on that
copy is the one that moves. Siblings: `extension-installer` (Core's side) and `marketplace` (the store).

## Idea

The owner, translated from Russian: "What if the admin only prepares a ticket file saying what I want,
here are the keys and the id, and a root cron checks for such a ticket every minute and, if there is
one, runs the build?" And: "The install agent is a separate product, as I understand it, so it gets its
own spec."

## Goal and problem

- Who is worse off without this, and how: a self-hosted operator, who needs a developer to add a
  Solution or upgrade Core (edit the Composer overlay, rebuild the image, migrate, restart) — and the
  owner, who cannot deliver a product to such an installation without that developer.
- What is true when the work is done: a ticket written by the admin turns into a running installation
  of the wanted set — Core and products — through an immutable image, with preflight, maintenance mode,
  migrations, a switch and an honest rollback, on compose first and through adapters elsewhere; and the
  agent updates itself the same way.

## Stress test

- Hidden assumptions — "this holds only if …":
  - The ticket can be trusted to be only data. Holds only if the agent executes nothing from it but a
    list of packages and versions checked against its own rules; a ticket field that reaches a shell
    is root for whoever can write the ticket — and every installed extension can write it.
  - The agent outlives Core versions. Holds only if the ticket contract is versioned and the agent
    supports a range of it; an agent tied to one Core release cannot upgrade Core.
  - Operators will run a root service from us. Not a given: `fapost-install` today promises it
    "installs no packages, registers no services, and touches nothing under /etc".
- The main trade-off: a host service with Docker rights (immutable images, safe switches) against no
  agent at all (nothing privileged to trust, but a developer for every change). Chosen: the agent,
  optional, with the manual path kept first-class in Core.
- The weakest point: it is root on someone else's server; one injection in ticket handling, one
  unsigned self-update, and the agent is the attack.
- Failure modes — cause, what breaks, the signal that shows it (independent failure hunt):
  - Partial upgrade — migrations in some tenant schemas, then a failure; DDL cannot be undone; signal:
    errors for some tenants only.
  - Queued jobs with classes the new release removed; signal: a spike of unserialize errors after a
    switch.
  - Several replicas or hosts, a switch that reaches only some — different requests served by
    different code; signal: intermittent 500s that a full restart cures.
  - The marketplace down or a licence expired while the operator rebuilds — "keeps working after
    expiry" broken at the rebuild; signal: a wave of failed builds at one time, 401/5xx from the
    repository.
  - Air-gapped installations covered by the offline licence but not by delivery — enterprise deals
    stall on "how do we update without internet".
  - Two install paths (agent and manual), only one of them really tested; signal: telemetry shows most
    installs going through the manual path.
- Other shapes considered, and why this one: see Decisions.

## Scope and non-goals

- In scope: picking up and validating tickets; building the derived image with Composer (licence keys
  as Composer auth against the marketplace repository); preflight; maintenance mode, pausing queues,
  migrations, the switch, resuming; rollback up to migrations; writing status back; a local artifact
  cache; offline bundles; adapters for compose, Kubernetes and an integrator's CI; self-update;
  installation by `fapost-install`.
- Not doing: anything the admin shows (`extension-installer`); the store, licences and the repository
  (`marketplace`); running anything a ticket contains other than packages and versions.

## Decisions

- The admin writes a declarative ticket; the agent on the host executes it — the owner's design.
  Rejected: launching a one-off container from the admin, and writing `vendor/` to a shared volume at
  run time (both see `extension-installer`): each puts host rights or divergent code inside the
  application, where every extension runs with full rights.
- Triggered by a systemd path unit, so a ticket is picked up as soon as it appears; a root cron is the
  fallback where systemd is absent.
- The ticket is data, never commands: packages, versions, licence references and the installation id;
  checked against the agent's own rules before anything runs.
- The result is an immutable derived image; the agent builds it and switches the stack to it.
- Install order: preflight in a separate process, maintenance mode, pause queues, migrations, switch,
  resume. Rollback only up to migrations, forward after them; the status says which.
- Status is written back for the admin: progress, the current step, errors, whether rollback is still
  possible.
- A separate repository — rejected: living in Core's repository like `gateway/`, because the agent
  upgrades Core and so must outlive any one Core version; the gateway ships with Core, the agent does
  not.
- Go, one static binary — rejected: shell (too fragile for signature checks, caching and status
  reporting); PHP in a container (needs the Docker socket inside a container again). Go is already in
  the project (the gateway).
- The ticket contract is versioned (`ticket.v1`) and lives in Core's `contracts/installer/` with golden
  examples both sides test against; the agent supports a range of contract versions.
- Adapters: compose is first-class (cron/systemd on the host); Kubernetes through a CronJob or an
  operator; an integrator's CI where the ticket becomes a commit to the overlay and their pipeline
  builds the image. Which adapters matter depends on the integrator and cannot be guessed — compose
  first, the rest when asked.
- Versions released while a licence was valid stay downloadable forever; the agent keeps a local cache
  of the artifacts it installed, so a rebuild does not depend on the marketplace being up.
- Offline bundle for air-gapped installations: a signed archive of a release set the agent imports.
- Self-update through an `agent.update` ticket, signature checked before the binary is replaced.
- Installed by `fapost-install` together with Core, as an explicit optional step.

## Open questions

- `fapost-install` promises no services and nothing under `/etc` — does installing the agent become an
  opt-in flag, a separate installer, or a change of that promise? Depends on how operators react to a
  root service.
- Where licence keys live: in the ticket (on disk, readable on the host) or fetched by the agent from
  the admin through an authenticated call — shared with `extension-installer`.
- How the switch reaches several hosts or replicas at once on compose (one host is assumed now).
- What the agent does with a ticket for a contract version it does not know — refuse with a status the
  admin can show, presumably, but the upgrade path for the agent itself must come first.
- Whether zip plugins are also baked into the image, ending the shared-volume channel — depends on how
  long an agent build takes in practice.

## Set aside

- unexplored: the agent as a general operations tool — backups, certificates, health — beyond installs.

## Assumptions left untested

- A build-and-switch takes minutes, short enough for maintenance mode — taken at deep; the first real
  upgrade measures it.
- Most self-hosted installations run compose on one host — taken at deep; installs reporting their
  adapter test it.
