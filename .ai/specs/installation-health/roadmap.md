# Roadmap — Installation health

Destination: the people who run an installation open one screen in the console and see, in its own
look, whether live updates, webhook intake and the queues work — and the operator of a multi-tenant
installation gets the same figures through a contract instead of a shell.

## Phase 1 — Realtime and the gateway on one screen

Goal: the two services switched on without any view get one. Done when: on a single-tenant
installation the page shows Reverb (up, down or unknown, connections, channels) and the gateway
(health, the published specs, channels still on the old URL), and opening it with Reverb stopped
shows "down" with the reason, not an error.

- [ ] Health service and probes — a read-only service that asks Reverb's HTTP API, the gateway's
      health endpoint and the webhook registry, with a short cache and timeout, and answers up, down
      or unknown for each; `gateway:doctor` reads its checks from the same place
- [ ] Health page — the console page on the kit with the Reverb and gateway cards, polled, empty and
      unknown states included, for the tenant's admins on a single-tenant installation (after:
      Health service and probes — the page draws what the service reports)
- [ ] Tenant slice in host mode — the same page in `host` mode shows only what is the tenant's: its
      live updates and its channels' ingress, never another tenant's or installation-wide figures
      (after: Health page — it narrows the page that exists)

## Phase 2 — Queues and the operator

Goal: the queue is on the page, and the operator of a multi-tenant installation sees the whole.
Done when: the page shows queue wait and throughput per named queue, and the SaaS operator panel
renders the installation-wide figures it receives through a contract.

- [ ] Queue card — wait time and throughput of the named queues from Horizon's metrics, and the
      failed-job count, on the page (after: Health service and probes — a new probe in the service)
- [ ] Health contract — a Foundation contract that returns the installation-wide health to a
      package that renders it (the monitoring package, the SaaS operator panel), with Core's default
      answer for an installation without one (after: Health service and probes — the contract
      exposes the same service)

## Phase 3 — Later

- [ ] fog: monitoring package — a separate repository and spec for Pulse, Horizon's access, a log
      viewer and later tools, gated for the installation's super admins; Core's part is the Health
      contract above, the rest waits for the package's name, licence and sign-in answers
- [ ] fog: history — a short window of samples behind a sparkline; needs a place to keep them

## Waves

1. Health service and probes
2. Health page; Queue card; Health contract
3. Tenant slice in host mode

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
