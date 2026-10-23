# Installation health: the state of Reverb, the gateway and the queues in one screen

Depth: normal — a new operator screen with a data-source decision (a first-party package against our
own page) and a question of who may see installation-wide figures. The reversible choices are taken
below as assumptions; the open ones are listed under "Open questions".

## Idea

Collected on 2026-10-22, after Reverb and the Go gateway were switched on in the first production
installation. The owner's words:

> а есть вообще какой-то механизм который покажет типа доктора состояние вебсокетов, кол-во
> каналов и т.п

> нет, мне нужен красивый юай, и я так понимаю, что это должно быть в коре

> пульс я так понимаю, показывает гораздо больше, там и нагрузка если мне не изменяет память

## Goal and problem

- Who is worse off without this, and how: whoever runs an installation cannot see whether live
  updates and webhook intake work without a shell. Today the facts are spread: `gateway:doctor`
  covers the gateway only, `ops:webhook-registry-health` the webhook registry, Horizon the queues,
  and nothing covers Reverb (connections, channels). On the first production run the Reverb figures
  had to be fetched by hand through Reverb's HTTP API from `tinker`.
- What is true when the work is done: an installation's staff open one screen in the console and see,
  in the console's own look, whether realtime and webhook intake are healthy: Reverb up or down,
  connections and channels, the gateway's health and how many channels still deliver to the old
  URL, queue wait and throughput, and the checks `gateway:doctor` makes today.

## Facts found

- Core has no check for Reverb. Reverb's own HTTP API answers `GET /apps/{id}/connections`,
  `/channels`, `/channels/{channel}` and `/channels/{channel}/users`, signed with the app secret;
  the application reaches it at `reverb:8080` inside Compose. Read on the first production run:
  1 connection, 1 channel (the owner's open Conversations screen).
- `laravel/reverb` ships two Pulse recorders (`ReverbConnections`, `ReverbMessages`). Pulse itself
  is not installed in Core.
- Horizon exposes queue metrics through `MetricsRepository` (throughput, runtime, wait times) and
  its own dashboard, which is closed on tenant hosts and gated by `viewHorizon`.
- Core's console screens are Inertia and Vue on the console kit, in the Warm Minimal direction;
  Filament is being removed from Core, so nothing new is built on it.
- Installation-wide figures need an audience: in `host` tenancy mode a tenant's staff must not see
  other tenants, and Core has no platform sign-in of its own (the SaaS operator panel brings one,
  and Horizon waits for it).

## Stress test

- Hidden assumptions — "this holds only if …": a page of installation-wide figures is acceptable
  only to people who run the installation; in `single` mode that is the tenant's admins, in `host`
  mode it is the operator, who is not a Core user.
- The main trade-off: a first-party package (Pulse) gives load, slow jobs and exceptions for free
  and ships Reverb cards, against a screen of our own that matches the console and shows what we
  choose, at the price of building it.
- The weakest point: a health screen that is wrong is worse than none. Every figure needs a plain
  "unknown" when its source cannot be reached, never a green tick by default.
- Failure modes — cause, what breaks, the signal that shows it: the Reverb API is unreachable from
  the app container (wrong host or secret) and the page reports "down" while sockets work, the
  signal being a connected browser next to a red card; a figure polled on every page load puts the
  load of a monitor on the thing it monitors.

## Scope and non-goals

- In scope: the page with the Reverb and gateway cards, then the queue card; the read-only service
  behind it; the Foundation contract for the operator; documentation of what each state means.
- Not doing: business analytics (spec `operator-insights`); alerting and paging; history beyond a
  short window unless a decision below asks for it.

## Decisions

- Decided 2026-10-22 (owner), then revised the same day: Core gets a page of our own in the console,
  built on the console kit in the Warm Minimal direction, for everyone who runs an installation.
  Pulse, Horizon's access, a log viewer and whatever operator tool comes next do **not** go into
  Core: they go into a separate package (working names `monitoring` or `maintenance`) that
  whoever wants full control installs. Rejected: Pulse inside Core, because Pulse requires
  `livewire/livewire` and Core has just left Livewire together with Filament (its `composer.json`
  no longer holds either), so it would bring a second front-end stack back into the ground floor;
  rejected: Pulse inside the SaaS package alone, because it would leave every self-hosted
  installation without it; rejected: a page of ours instead of Pulse, because it would never show
  server load, slow requests and exceptions as well as Pulse does. The package keeps the SaaS
  panel's "Maintenance" links and log viewer (fapost-lab/saas#88, #90) as the first things it
  could take over.
- Decided 2026-10-22 (owner): the audience of Core's page follows the tenancy mode. `single`: the
  tenant's admins see everything, they are the operators. `host`: a tenant's admin sees only the
  slice that is theirs (live updates for their assistants, their channels' ingress). The whole
  installation's figures leave Core through a Foundation contract, read by the monitoring package
  and by the SaaS operator panel, each in its own look — rejected: a platform page in Core with a
  sign-in of its own, which is a separate large project.
- Decided 2026-10-22 (owner): the package is `fapost/monitoring` (namespace `Fapost\Monitoring`,
  repository `fapost-lab/monitoring`) — rejected: `observability` (longer, heavy in configs and
  commands), `ops` (clashes with Core's `ops:` command prefix and says nothing about what is
  inside), `maintenance` (reads as Laravel's maintenance mode). It is Apache-2.0 and open, like
  Core, Foundation and Support, so that self-hosted installations can install it — rejected:
  closed like the SaaS package, which would make "full control" a paid choice. The software it
  wires (Pulse, Horizon, a log viewer) is MIT, which Apache-2.0 does not conflict with. It depends
  on Foundation and Support only, never on Core's `App\*` classes, as every package here does.
- Decided 2026-10-22 (owner): sign-in is not the package's job. A Foundation contract answers "may
  this request look at the operator screens", and the package only asks it — rejected: a sign-in
  of its own for the package (a second authentication system beside the SaaS operator's, with
  passwords, two-factor and invites to secure) and an e-mail list in `.env` (cannot be kept in step
  with roles, and means nothing without a sign-in). Core's default answer: on a `single`
  installation the signed-in owner and admins of the tenant; on a `host` installation closed,
  until an operator layer binds its own answer (the SaaS package for its Owner and Admin
  operators, replacing the bridge it made for Horizon in fapost-lab/saas#84). The same contract
  opens Horizon on a `single` installation, where today its gate is closed for everyone.
- Decided 2026-10-22 (owner): Telescope moves into the monitoring package as well, next to Pulse,
  Horizon's screens and the log viewer. Today it is only a `require-dev` dependency of Core
  (`dont-discover`, wired by `App\Providers\TelescopeServiceProvider`, with the `TELESCOPE_ENABLED`
  setting and its guard against a missing class in production images). What follows from it is
  listed under "Open questions" so that the move loses nothing Core knows.
- Assumed (reversible): one read-only service builds the figures and every consumer — the console
  page, the contract, and `gateway:doctor` where it overlaps — reads from it, so a check is written
  once.
- Assumed (reversible): the page refreshes by polling, not over Reverb: a page that reports the
  health of the websocket must not depend on it. Each probe is cached for a few seconds and times
  out quickly, so opening the page costs the monitored services next to nothing.
- Assumed (reversible): a figure whose source cannot be reached shows "unknown" with the reason,
  never a green tick; "down" is shown only when the service answered that it is down.
- Assumed (reversible): the first slice is Reverb and the gateway, the two things switched on
  without any view; queues (Horizon's metrics) follow as the second.

## Open questions

- The contract's name and shape (a request in, a yes or no out, or a user and a surface) — the
  first task in Foundation settles it with the callers in view: the package, the SaaS panel and
  Core's own Horizon gate.
- Whether the SaaS panel's Maintenance group becomes a client of the monitoring package or stays
  as it is — decided in the package's own spec, once it exists.
- Telescope's masking rules are Core's knowledge: `hideRequestParameters(['_token', 'token'])` and the
  ignored path `/support/enter` (the single-use secret of the support access entry), plus the header
  masking outside `local`. The package must not depend on Core, so they have to travel some other
  way — Core keeps setting the inert `telescope.*` configuration keys, or a Foundation contract
  lists what no recording may keep — and the move must not leave a window where Telescope records
  that secret. Decided in the move's own task, with a test that the secret is never stored.
- Core's developers would take Telescope from the package: a development checkout links
  `fapost/monitoring` (`composer dev:link`) instead of requiring Telescope, and the test suite must
  not start depending on the package; whether that is acceptable, or Core keeps a dev-only copy of
  the provider, is settled in that task as well.
- Telescope in production records request contents and is heavy: off by default in the package,
  behind the operator-access contract when switched on.
- Where the page sits in the console's menu and its name ("Health", "System", under Settings) — a
  design detail for the first task, with the console-screens convention in hand.
- Whether a short history (a sparkline of the last hour of connections) is wanted, which needs a
  place to keep samples — left in fog until the first slice shows what is missed.

## Assumptions left untested

- That Reverb's HTTP API is reachable from the `app` container with the app secret on every
  installation shape (Compose, bare metal) — it was on the first production one.
