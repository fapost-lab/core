---
id: feature-installation-health-service
type: feature
status: active
domains: []
paths:
  - "app/Domains/Health/**"
  - app/Http/Shell/TenantHealthSlice.php
reviewed_at: 2026-10-27
---
# Installation health service

## What

`HealthService` (`app/Domains/Health`) asks four probes — Reverb's HTTP API, the gateway's `/healthz`, the
webhook registry against Redis and Horizon's queue metrics — and answers each with a `ProbeResult`: a `HealthState`, a reason and the figures
measured. Each answer is cached for the few seconds its probe declares. It is read-only and returns installation-wide,
unfiltered data. Core's console page reads it directly; packages read it through Foundation's
`InstallationHealthInterface` (0.34), answered by `CoreInstallationHealth` (bound with `bindIf`). `gateway:doctor` reads its gateway check from `GatewayProbe::probeEndpoint()` and its spec check from
`IngressSpecPublisher::inspect()`.

## Why

On the first production run nothing could show whether Reverb or the gateway worked; the figures were fetched by hand.
One service means a check is written once for the console page, the contract the monitoring package reads and the
doctor command.

## Constraints

- **`down` only when the service answered that it is unwell** (HTTP 5xx, a non-2xx `/healthz`, a drifted registry).
  Unreachable, timed out, rejected signature, missing settings and a probe that threw are `unknown` with a reason;
  nothing defaults to `up`. A stopped Reverb therefore reads `unknown` ("could not be reached"), not `down`: from the
  application the same refusal is a wrong `REVERB_HOST`.
- **Switched off on purpose is `disabled`**, not an error: `BROADCAST_CONNECTION` not `reverb`, ingress driver `laravel`,
  the default queue connection not on the `redis` driver.
- **In `host` mode the console narrows the report through `App\Http\Shell\TenantHealthSlice`**, never in the domain:
  Reverb channels are counted by the `tenant.{id}.` prefix, gateway drift comes from a tenant-scoped registry count,
  and there are no reasons, no queue card and no connections, addresses or specs.
- **`QueueProbe` reads Horizon and never writes to it.** Horizon not running (no master supervisor, or all paused) is `down`:
  Redis answered and nothing is processing jobs. An unreadable Redis is `unknown`. It goes through `HorizonQueueMetrics`,
  which reads `last_snapshot_at` as a plain key: `MetricsRepository::jobsProcessedPerMinute()` and
  `minutesSinceLastSnapshot()` stamp it and would move the window `horizon:snapshot` closes. A queue no running
  supervisor serves has a wait of `null`, never `0`; `horizon:snapshot` is scheduled every five minutes in
  `routes/console.php`, without which the per-minute figures never settle. Wait thresholds come from `horizon.waits`
  (read as a whole array, its keys contain dots), default 60 s.
  Its figures are the whole installation's: a tenant's slice in `host` mode must leave the queue card out, not narrow it.
- **The Reverb secret never leaves `ReverbApiClient`:** requests are signed by hand, and no exception message, reason
  or detail repeats the URL (it carries the signature) or the secret. Short timeouts (3 s, 2 s to connect).
- **No tenant filtering here.** `details` can hold channel names with a tenant id; the consumer narrows them.
- The cache is a courtesy: if it fails the probe runs live.
- **What leaves Core is an allow-list.** `CoreInstallationHealth` passes counts and the names of queues and platforms
  only — never `channel_names`, exception texts, HTTP statuses or tenant ids — and every reason and endpoint goes
  through `Support\UrlScrubber`, the one rule for "a URL without credentials" (the console page uses it too). A new
  probe or figure reaches packages only when it is added to the adapter and to Foundation's metric catalogue.
- **The contract carries data, not permission.** It takes no request and authorises nobody: a caller asks
  `OperatorAccessInterface` for `OperatorSurface::Health` first. That keeps one access decision and lets a caller
  without a request (a scheduler) read it.
- The adapter never throws: a service it cannot translate is `unknown`, a report it cannot build is every known
  service `unknown`. A probe Foundation has no `MonitoredService` for is left out, not guessed.

## Decisions and rejected alternatives

- A domain of its own, not a part of `channel-ingress`: Reverb is not ingress, and the consumers read all three probes.
- Reverb is read with a hand-signed `Http` request, not the Pusher SDK behind the broadcast driver: the SDK's client has no
  per-call timeout through Laravel's configuration, and `Http::fake` cannot intercept it.
- The contract's figures are a generic `HealthMetric` (name, value, unit, subject) with a documented catalogue, not
  a typed DTO per service: a new figure or service is a Foundation minor, not a breaking change. Rejected: exposing
  `HealthReport::toArray()` (untyped, leaks channel names and exception texts, ties packages to Core's `details`).
- Spec: `.ai/specs/installation-health/` (branch `spec/installation-health`).
