# First-contact path

Depth: normal — the pieces exist separately (self-hosting pages, a demo content seeder, the
provisioning service); what is open is the path through them and the number it is measured against.

## Idea

Step 2 of the product roadmap, in the owner's words: "First-contact path — installation, a seeded
demo assistant and self-hosting docs, with a named target time from install to a working
assistant."

## Goal and problem

- Who is worse off without this, and how: the developer or integrator meeting FaPost for the first
  time. Nothing today takes them from a clean machine to an assistant that answers a message: the
  self-hosting section documents the services, `DemoContentSeeder` fills an existing tenant's
  screens for a documentation walkthrough, and the gap between those two is filled by knowing the
  project. Every minute in that gap is where a first-time reader leaves.
- What is true when the work is done: a named, measured path — install, provision, seed, connect a
  bot, send a message and get an answer — that a first-time reader completes within a time the
  project has committed to out loud.

## Stress test

- Hidden assumptions — "this holds only if …":
  - That the reader can bring a real Telegram bot token within the path. An assistant that answers
    needs a channel, and a channel registers a webhook with the provider — so the path either
    requires a token or ends one step short of "working".
  - That "install" means the Docker Compose path. The self-hosting section also documents bare
    metal; a target time that only holds for one of them has to say which.
  - That a single-tenant install is still a tenant. It is — Core fails fast without a tenant
    context — so provisioning is part of the path, not a multi-tenant extra.
- The main trade-off: a demo that works out of the box teaches the platform quickly and risks
  teaching the wrong thing — a reader who never provisions a tenant or builds a flow has not met
  the product, only its screenshot.
- The weakest point: the target time has never been named (open decision D2), so "faster" has no
  meaning and nothing can fail the check. Until it is a number, this spec has a goal it cannot
  measure.
- Failure modes — cause, what breaks, the signal that shows it:
  - The demo seeder is treated as the path — it targets a tenant and an assistant that must already
    exist, so a first-time reader hits `firstOrFail` on an empty database.
  - The path is measured by someone who already has the environment warm — the number is real for
    nobody; the measurement has to start from a clean machine, images unpulled.
  - The webhook cannot reach the install — the assistant looks configured and answers nothing; this
    is the most likely place a first run dies and the troubleshooting page has to meet it there.
- Other shapes considered, and why this one:
  - A hosted sandbox instead of an install — rejected: the audience is self-hosting integrators,
    and the thing being tested is precisely whether the install is easy.
  - Documentation only, with no seeded demo — rejected: reading is not the same as having an
    assistant answer, and the promise is a working assistant.

## Scope and non-goals

- In scope: the installation path a first-time reader follows, a seeded demo assistant that works
  on a fresh install (not only on a populated tenant), the self-hosting pages rewritten around that
  path, and the measurement that produces the named time.
- Not doing: the positioning rewrite (a separate task — what the project says it is, not how fast
  it starts); the extension story (steps 4–7); a hosted trial; additional channels.

## Decisions

- The path ends at an assistant answering a real message on a real channel — rejected: ending at
  "the panel opens", because the promise is a working assistant, not a running container.
- The demo is seeded, not imported by hand — the existing `DemoContentSeeder` is the starting
  point; it fills an existing tenant, so the path needs a seeded tenant and assistant before it.
- Published as the site's Self-Hosting section, where a first-time reader already looks — rejected:
  a separate quickstart repository, which is one more thing to keep true.

## Open questions

- D2 (from the product roadmap): what is the target time from a fresh installation to a first
  working assistant, as a number we are willing to be measured against — a human decision. Without
  it, nothing here has a pass mark.
- Which install path carries the promise: Docker Compose, bare metal, or both with different
  numbers.
- Whether the path requires the reader's own bot token, or ships something that answers without
  one — it decides whether the last step is reachable offline.

## Assumptions left untested

- That `DemoContentSeeder` can be extended into a first-run seed rather than replaced — taken at
  normal depth; running it against an empty database tests it in one command.
- That the current self-hosting pages are structurally right and only the path through them is
  missing — taken at normal depth; a first-time read-through tests it.
