---
id: adr-20261010-egress-guard-in-core
type: adr
status: accepted
date: 2026-10-10
domains:
  - flow
paths:
  - "app/Domains/Flow/Call/Egress/**"
summary: Why the call-node egress guard lives in Core's Flow domain despite ADR-05, and when it moves to fapost/support with a Foundation contract
---
# The egress guard lives in Core's Flow domain for now, and moves to fapost/support with a Foundation contract later

## Context

The `call` node requests a URL the flow author writes, often through a template over contact data, so
the target is untrusted. The egress guard (refuse non-public addresses after resolution and on every
redirect, pin the connection) is a primitive with no flow meaning. ADR-05 says such primitives belong to
`fapost/support`, and that extensions depend on Foundation contracts, never on Core classes.

Today the only consumer is the `call` node's HTTP transport, and there is no Solution or plugin with an
HTTP action. A move to `fapost/support` means a separate repository, a release and a Foundation contract
for a consumer that does not exist yet. The tenant-quotas spec treats the guard as a blocker for a public
stand.

## Decision

The guard lives in `App\Domains\Flow\Call\Egress` (`GuardedHttpClient`, `EgressGuardMiddleware`,
`AddressClassifier`, `HostResolverInterface`), with the only network-touching resolver in
`App\Infrastructure\Flow`. This departs from ADR-05 knowingly; the owner approved it at the human gate
on 2026-10-10 (question Q5).

When the first Solution or plugin with an HTTP action appears, the guard moves to `fapost/support` and a
Foundation contract is added for extensions. Until then extensions are not given a Core class: the
extension documentation states the requirement (tenant-steered outbound HTTP refuses private, loopback,
link-local and cloud-metadata addresses after resolution and on every redirect), and an extension that
makes its own request carries it itself. Inside Core, `tests/Architecture/EgressGuardTest.php` keeps Flow
code from making HTTP without the guard.

## Alternatives

- Put the guard in `fapost/support` now. Rejected for now: a cross-repository release plus a Foundation
  contract for a single internal consumer; the cost is paid when a second consumer exists.
- Expose `GuardedHttpClient` to extensions as API. Rejected: it would make a Core class a public
  extension dependency, which ADR-05 forbids.
- Guard only through network policy or an egress proxy. Rejected as the main defence: it depends on every
  operator's infrastructure and cannot be tested in Core; kept as an additional layer (`FLOW_EGRESS_PROXY`).

## Consequences

- The `call` node is protected on every installation now, with one place to maintain the address table.
- Extensions are not covered automatically; the requirement is documentation until the contract exists.
- The later move changes namespaces and adds a Foundation interface; `HostResolverInterface` already
  isolates the I/O, so the move is mechanical.
- The PHPat rule must follow the class when it moves.
