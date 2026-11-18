---
id: adr-20261118-connectors-licensed-mit
type: adr
status: accepted
date: 2026-11-18
domains:
  - channels
paths: []
summary: Official connectors are MIT without a CLA, so the next connector can be copied from one; Core, Foundation, Support and own Solutions stay Apache-2.0
---
# Official connectors are MIT and have no CLA; Core, Foundation, Support and FaPost's own Solutions stay Apache-2.0

## Context

Every package of the ecosystem started as Apache-2.0 with the CLA check (`CLA.md`, `.github/workflows/cla.yml`). The
first connector package, `fapost/connector-slack`, made the question concrete. A connector is a thin adapter to a
provider's API, and its main value beyond its own channel is as the example a connector for the next provider is
started from: the more connectors exist, the stronger Core is. Apache-2.0 asks whoever copies one to keep the licence
text and NOTICE and to mark the files they changed; MIT asks only for the copyright notice. The CLA exists to let the
project relicense contributions, which a connector does not need, and its check failed in the connector's repository
for want of a signatures branch.

## Decision

- Official connector packages (`fapost/connector-*`) are licensed MIT: `LICENSE` holds the MIT text, `composer.json`
  declares `"license": "MIT"`.
- Connector repositories run no CLA check; a contribution there is accepted under MIT (inbound = outbound).
- Core, `fapost/foundation`, `fapost/support` and FaPost's own Solutions stay Apache-2.0 with the CLA.
- Telegram stays in Core under Apache-2.0. Moving it into an MIT connector is possible only while its code has the
  owner as its sole author, or with its contributors' consent (the CLA's relicensing right covers signed
  contributions).

Applied in connector-slack #2 (CLA removed) and #3 (MIT).

## Alternatives

- **Apache-2.0 for connectors, like the rest.** Keeps one licence and an explicit patent grant, but leaves the copying
  friction on exactly the packages meant to be copied. Rejected by the owner.
- **Keep the CLA in connector repositories.** Buys a relicensing right a connector has no use for, at the cost of a
  signature step for every outside contributor. Rejected.
- **MIT for everything.** Would drop the patent grant and the relicensing right where they matter: on the platform
  third parties build businesses on. Rejected.

## Consequences

- A connector author may copy an official connector and license the result however they like, keeping one notice.
- Connectors carry no patent grant; for adapters over public HTTP APIs this risk is accepted.
- A new connector repository is created with the MIT `LICENSE` and without `cla.yml`. Code moved from Core (Apache-2.0)
  into a connector needs its authors' consent to be relicensed.
- The public pages say so: `docs/site/contributing/legal.mdx`, `docs/site/self-hosting/connectors-directory.mdx`.
