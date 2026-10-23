---
id: feature-installation-health-service
type: feature
status: active
domains: []
paths:
  - "app/Domains/Health/**"
---
# Installation health service

## What

`HealthService` (`app/Domains/Health`) asks three probes — Reverb's HTTP API, the gateway's `/healthz` and the
webhook registry against Redis — and answers each with a `ProbeResult`: a `HealthState`, a reason and the figures
measured. Each answer is cached for the few seconds its probe declares. It is read-only and returns installation-wide,
unfiltered data. `gateway:doctor` reads its gateway check from `GatewayProbe::probeEndpoint()` and its spec check from
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
- **Switched off on purpose is `disabled`**, not an error: `BROADCAST_CONNECTION` not `reverb`, ingress driver `laravel`.
- **The Reverb secret never leaves `ReverbApiClient`:** requests are signed by hand, and no exception message, reason
  or detail repeats the URL (it carries the signature) or the secret. Short timeouts (3 s, 2 s to connect).
- **No tenant filtering here.** `details` can hold channel names with a tenant id; the consumer narrows them.
- The cache is a courtesy: if it fails the probe runs live.

## Decisions and rejected alternatives

- A domain of its own, not a part of `channel-ingress`: Reverb is not ingress, and the consumers read all three probes.
- Reverb is read with a hand-signed `Http` request, not the Pusher SDK behind the broadcast driver: the SDK's client has no
  per-call timeout through Laravel's configuration, and `Http::fake` cannot intercept it.
- Spec: `.ai/specs/installation-health/` (branch `spec/installation-health`).
