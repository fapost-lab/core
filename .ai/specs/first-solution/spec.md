# First solution built through the public contracts

Depth: normal — the build itself is ordinary work; what needs deciding is the discipline it runs
under and what counts as a finding.

## Idea

Step 6 of the product roadmap, in the owner's words: "First solution built through the public
contracts only — no privileged access into the core, as an outsider would build it."

The engineering plan names the candidate: FaPost HR, registering `hr.sync_employee` and
`hr.create_assessment` action handlers.

## Goal and problem

- Who is worse off without this, and how: everyone downstream of the claim "this platform is easy
  to extend". Today the claim is untested — the extension surface was designed against an empty
  stub provider. Without a real build, the extension documentation (step 7) would describe
  contracts nobody has used, and the first outside integrator would find the gaps instead of us.
- What is true when the work is done: a Solution package living in its own repository is installed
  into a FaPost install, activated for a tenant, and does real work in a flow — with no change to
  Core made for its benefit that is not also a contract improvement, and with every gap it hit
  written down.

## Stress test

- Hidden assumptions — "this holds only if …":
  - That the author can stay outside Core. Holds only if every gap is closed in Foundation or in
    the Solution; the moment a fix lands in Core "just for HR", the test stops being a test.
  - That HR is a fair subject. It exercises action handlers and data writing; it does not exercise
    a Solution that ships a complex interface, so the UI half of the surface stays less tested.
  - That the activation lifecycle is finished first. Without it there is nothing to install into.
- The main trade-off: building it as a strict outsider is slower — every missing contract becomes a
  round trip through the Foundation repository instead of a quick Core edit — and that slowness is
  the measurement being taken.
- The weakest point: the builder is the author. Someone who knows Core's internals will
  unconsciously route around gaps an outsider would hit. Writing down every consulted Core source
  file is the cheap counter-measure: each one is a documentation gap.
- Failure modes — cause, what breaks, the signal that shows it:
  - A needed contract is missing and gets added to Core instead of Foundation — the dependency
    direction inverts and the next Solution cannot reuse it; the signal is a `use App\…` inside the
    package.
  - The Solution reads Core models directly because a read contract is missing — it breaks on the
    next Core refactor; the signal is any Eloquent model from `App\Domains` inside the package.
  - The Solution works only against the development symlinks and not against the published
    packages — the signal is an install from Packagist that fails where `composer dev:link` passed.
- Other shapes considered, and why this one:
  - A showcase Solution written inside this repository — rejected: it proves nothing about the
    contracts, because everything is reachable from inside.
  - Waiting for an outside integrator to build the first one — rejected: it hands the gaps to the
    audience the platform is trying to win.

## Scope and non-goals

- In scope: the Solution package in its own repository, built strictly against
  `fapost/foundation` and `fapost/support`; the contract additions it turns out to need, made in
  the Foundation repository; the record of every gap, workaround and consulted Core source, which
  is the input to step 7.
- Not doing: shipping the Solution as a product, its commercial packaging, or the marketplace that
  would distribute it (step 8); changes to Core that serve this one Solution rather than the
  contract.

## Decisions

- The Solution lives in its own repository, installed as a package — rejected: a folder inside
  Core, because the boundary being tested is exactly the one a folder removes.
- No privileged access: the package may not import `App\…` — settled by the dependency-direction
  rule in `AGENTS.md` and ADR-05; a gap is closed in Foundation or worked around in the Solution.
- Contract changes go into the Foundation repository, which is absent from this working tree and
  symlinked in for local development — settled by ARCHITECTURE.md.
- The subject is FaPost HR with `hr.sync_employee` and `hr.create_assessment` — carried over from
  the engineering plan (M7).

## Open questions

- Whether the Solution ships any builder UI in this first pass, or stays handler-only — it decides
  how much of the UI foundation (step 5) is actually exercised.
- What "done" means for the Solution itself: a demo of the two handlers, or something the owner's
  own client rollout runs on.
- Whether the gap record is a document in this repository or issues in the Foundation repository.

## Assumptions left untested

- That two action handlers exercise enough of the surface to justify rewriting the extension
  documentation on their evidence — taken at normal depth; a second Solution of a different shape
  would test it.
- That the published packages and the local symlinks behave identically — taken at normal depth; an
  install from the published channel into a clean environment would test it.
