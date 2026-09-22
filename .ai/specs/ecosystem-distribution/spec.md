# Ecosystem distribution

Depth: easy — almost nothing here is decidable yet; the purpose of the spec is to hold the
question, the blockers and the candidate concepts until the fog lifts.

## Idea

Step 8 of the product roadmap: "Ecosystem distribution — how a third-party extension reaches
another installation and what it is allowed to do once there." The product roadmap files it under
"Not yet specified"; the engineering plan carries the older sketch of the same ground as Milestone
11 — a plugin registry separate from activation, runtime install without a deploy, a plugin store
UI, a sandbox, and a developer SDK.

## Goal and problem

- Who is worse off without this, and how: the author of a third-party extension, who is what
  confirms the ecosystem exists. They can write a Solution but have no way to put it in front of
  installations they do not own; and an operator has no way to judge what an installed extension is
  allowed to do.
- What is true when the work is done: unknown in detail — that is the point of the fog. At minimum:
  an extension written by someone else reaches an installation through a named channel, and what it
  may do at runtime is bounded by something stronger than trust.

## Stress test

- Hidden assumptions — "this holds only if …":
  - That distribution is a platform problem at all. If Composer plus a private repository is
    sufficient for the integrators who actually exist, the store is a solution to a problem nobody
    had.
  - That a sandbox is achievable in-process. A PHP package installed into the application shares
    everything with it; a boundary that means anything may require a different execution shape.
  - That a paid closed extension can be installed at all without a licensing mechanism nobody has
    designed.
- The main trade-off: runtime install without a deploy is what makes a store feel like a store, and
  it is exactly what makes the sandbox question unavoidable. A deploy-time install channel is
  weaker as a product and far cheaper to make safe.
- The weakest point: there is no evidence yet. The recon pass is deliberately deferred until the
  first Solution has been built through the public contracts, because that build is what reveals
  which contracts an outsider actually touches.
- Failure modes — cause, what breaks, the signal that shows it:
  - A marketplace built before the contracts settle — every extension in it breaks on the next
    contract change; the signal is a version story nobody can state.
  - An extension reaching landlord data or registering worker pools — one tenant's install
    compromises another's; this is why the sketch names those two as the first things a sandbox
    must forbid.
  - The distribution decision made to serve monetisation rather than the stated goal — the brief is
    explicit that earning money is not this stage's goal, and open decision D3 has not been settled.
- Other shapes considered, and why this one:
  - Building the plugin store now, from the Milestone 11 sketch — rejected: the sandbox boundary
    cannot be stated before the activation surface is fixed, and the contracts it would distribute
    have not survived a real build.
  - Declaring Composer the only channel and closing the question — a live candidate, not yet
    rejected; the recon pass has to weigh it.

## Scope and non-goals

- In scope: the recon pass itself — what an extension may do at runtime, where the sandbox boundary
  sits, whether distribution rides on the existing package channel or needs its own, and what a
  paid closed extension needs in order to be installable at all.
- Not doing: building a store, a plugin registry or an SDK before that pass has an answer; the SaaS
  shell's plan-to-activation mapping, which is the owner's separate closed product.

## Decisions

- The question is not opened until the first Solution has been built through the public contracts —
  rejected: specifying distribution from the Milestone 11 sketch, because that sketch predates any
  evidence about which contracts an outsider touches.
- A plugin registry, if it happens, is separate from the activation registry — carried over from
  the Milestone 11 sketch as a candidate, not a settled decision.
- Whatever the channel turns out to be, an extension does not reach landlord data and does not
  register worker pools — this follows from the tenancy invariants in `RULES.md`, not from the
  store design.

## Open questions

- D3 (from the product roadmap): how "not earning money right now" reconciles with a shell, billing
  and a marketplace remaining in the plans — a human decision, and this step is where it lands.
- Whether distribution rides on the existing package channel or needs its own.
- What a paid closed extension needs in order to be installable at all.
- Whether runtime install without a deploy is a requirement or an aspiration.

## Assumptions left untested

- That the ecosystem needs a distribution mechanism beyond Composer — taken at easy depth; asking
  the integrators who exist today would test it, before anything is built.
- That the Milestone 11 concepts (plugin registry, store UI, sandbox, SDK) remain the right
  vocabulary once the recon pass runs — taken at easy depth.
