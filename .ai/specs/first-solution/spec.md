# First solution: Feedback, and the scaffold every Solution starts from

Depth: normal — the build itself is ordinary work; what needs deciding is the discipline it runs
under, what counts as a finding, and the shape of the two feedback audiences.

## Idea

Step 6 of the product roadmap, in the owner's words: "First solution built through the public
contracts only — no privileged access into the core, as an outsider would build it."

Subject changed by the owner on 2026-10-07, from FaPost HR to a feedback Solution: "a 'help us
improve' button on the side; clicking it opens a modal with bug reports and improvement requests,
with likes and dislikes. Something like it exists as a service, but I want my own simple one, to
try Solutions out. Every such message arrives in Telegram." Refined in the same conversation:

- One widget, two sections. The tenant's section is a tenant supporting its own customers; the
  FaPost section is improvement requests to the FaPost team, shared across installations.
- Delivery differs: a direct Telegram message for the FaPost team; a flow for the tenant.
- "On top of this Solution we must build a boilerplate for other developers."
- "We meant Solutions as independent apps on top of Core; Core is a communication tool, so a
  Solution may have its own tables, its own pages and so on."

## Goal and problem

- Who is worse off without this, and how: everyone downstream of the claim "this platform is easy
  to extend". The claim is untested — the extension surface was designed against an empty stub
  provider. Without a real build, the extension documentation (step 7) would describe contracts
  nobody has used, and the first outside integrator would find the gaps instead of us. And a
  developer starting a Solution today has nothing to start from.
- What is true when the work is done:
  - The Feedback Solution, in its own repository, is installed, activated for a tenant and used:
    staff and customers send bug reports and suggestions, vote on them, the tenant's team triages
    them in its own pages and hears about each through a flow; suggestions about the platform reach
    the FaPost team's shared board and Telegram.
  - No change was made to Core for its benefit that is not also a contract improvement, and every
    gap it hit is written down.
  - `artisan` in Core generates a new Solution package that installs and passes its own tests on
    the Core it was generated from.

## What a Solution is here

A Solution is an independent application on top of Core, in its own Composer package: its own
domain, tables, migrations, pages, routes and permissions (as `GLOSSARY.md` already says). Core
is the communication platform it uses — staff identity and permissions, contacts, channels,
flows, the console's shell and kit. A Solution reaches Core only through Foundation contracts and
the UI extension contract; its own tables and pages are not gaps, a missing contract to Core is.

## The Feedback Solution

- **One widget, two sections.** A "help us improve" button sits in the console's shell on every
  screen (and in the mini app for customers); its modal has two tabs:
  - **To our team (tenant).** Written by the tenant's staff in the console and by the tenant's
    customers through the mini app or the bot; one list per tenant with bug reports and
    suggestions, likes and dislikes, statuses; triaged by the tenant's team on the Solution's own
    pages; each new item raises an event that starts a flow, and the flow notifies whoever the
    tenant configured through its channel.
  - **To the FaPost team (platform).** Improvement requests about FaPost itself, on one shared
    public board across every installation. The board lives on the FaPost team's own instance,
    which runs the same Solution in receiver mode; widgets everywhere read the board and vote on
    it through that instance's API. The receiver sends the FaPost team a direct Telegram message
    for each new item. On in the SaaS; off by default for self-hosters and switched on in
    settings, because it sends data out of the installation.
- **The modal suggests similar items before a new one is filed**, so people vote instead of
  duplicating.

## Stress test

- Hidden assumptions — "this holds only if …":
  - That the author can stay outside Core. Holds only if every gap is closed in Foundation or in
    the Solution; the moment a fix lands in Core "just for Feedback", the test stops being a test.
  - That Feedback is a fair subject. It exercises the UI half of the surface (its own app in the
    console, a shell slot, contextual actions, the mini app), its own data, events into flows and
    an outbound and inbound API. It does not exercise action handlers in the builder's `call`
    node — HR was the subject for that, and is dropped (see Decisions).
  - That the UI foundation's extension contract and the activation lifecycle exist first. Without
    them there is nowhere to put the Solution's pages and nothing to activate.
  - That a public cross-installation board is acceptable to self-hosters. Holds only with explicit
    opt-in, a visible statement of what leaves the installation, and pseudonymous voter identity.
- The main trade-off: building as a strict outsider is slower — every missing contract becomes a
  round trip through the Foundation repository instead of a quick Core edit — and that slowness is
  the measurement being taken.
- The weakest point: the shared FaPost board. It is a public, cross-installation service — voter
  identity across installations, moderation of public text, spam, privacy — inside what was meant
  to be a simple first Solution.
- Failure modes — cause, what breaks, the signal that shows it:
  - A needed contract is missing and gets added to Core instead of Foundation — the dependency
    direction inverts and the next Solution cannot reuse it; the signal is a `use App\…` inside
    the package.
  - The Solution reads Core models directly because a read contract is missing — it breaks on the
    next Core refactor; the signal is any Eloquent model from `App\Domains` inside the package.
  - The Solution works only against the development symlinks and not against the published
    packages — the signal is an install from Packagist that fails where `composer dev:link` passed.
  - The scaffold command rots — Core changes, generated packages stop installing, nobody notices
    until an outside developer does; the signal is a generated package that fails `composer
    install` or its own tests.
  - The public board leaks what a tenant did not mean to publish — a customer's bug report with
    personal data reaches every installation; the signal is a takedown request.
- Other shapes considered, and why this one:
  - FaPost HR with two action handlers (the original subject) — dropped by the owner: Feedback
    exercises far more of the surface, and the owner uses it.
  - A showcase Solution written inside this repository — rejected: it proves nothing about the
    contracts, because everything is reachable from inside.
  - Waiting for an outside integrator to build the first one — rejected: it hands the gaps to the
    audience the platform is trying to win.
  - Collecting platform feedback in the SaaS package's landlord database — rejected: self-hosters
    would have no way to reach the FaPost team, and that half would not be a Solution at all.

## Scope and non-goals

- In scope: the Feedback Solution in its own repository, built strictly against
  `fapost/foundation`, `fapost/support` and `@fapost/ui`; its receiver mode on the FaPost team's
  instance; the contract additions it turns out to need, made in the Foundation repository (and
  in the UI extension contract); the record of every gap, workaround and consulted Core source,
  which is the input to step 7; the scaffold command in Core and its CI check.
- Not doing: selling the Solution or its commercial packaging; the marketplace that would
  distribute Solutions (step 8); changes to Core that serve this one Solution rather than the
  contract; action handlers in the builder (no HR).

## Decisions

- **The subject is Feedback, and only Feedback** (owner, 2026-10-07) — rejected: FaPost HR, the
  original subject; rejected: both, in sequence or in parallel (one developer, and Feedback covers
  more). Action handlers in a `call` node stay unexercised by a Solution until a later one needs
  them.
- **Solutions are independent applications on top of Core** — own tables, pages, routes and
  permissions are normal, not gaps; only a missing contract to Core is a gap.
- **Two sections in one widget**: the tenant's (staff and customers, both) and the FaPost team's
  — rejected: a tenant-only Solution with platform feedback left to the SaaS package (no reach to
  self-hosters).
- **The tenant's section is written by staff and customers alike** — staff from the console,
  customers from the mini app or the bot; one list per tenant.
- **Delivery: a flow for the tenant, a direct Telegram message for the FaPost team** — the tenant
  configures recipients and text in the builder; the receiver instance messages the FaPost team
  through the Bot API with its own token.
- **The FaPost board lives on the FaPost team's own instance, running this Solution in receiver
  mode**, reached through its API from every installation; off by default for self-hosters —
  rejected: the SaaS landlord database (self-hosters excluded); rejected: each installation's own
  landlord (Solutions have no landlord contract, and it would not reach the FaPost team).
- **The FaPost board is shared and voted on from the start** (owner's choice; the recommendation
  was submission first, board later) — widgets read the public board and vote through the
  receiver's API. Cost accepted: voter identity across installations, moderation and spam are in
  the first version.
- **The boilerplate is a generator command in Core** (owner: "when Core updates, the template
  updates with it and stays up to date") — `artisan` generates a Solution package with one example
  of each extension point, tests and CI. Core's CI generates a package with the command on every
  run, installs it and runs its tests, so a Core change that breaks the template fails Core's own
  build. The Feedback Solution stays the full worked example the docs are written from. A command
  updates only new Solutions; existing ones follow the changelog. — rejected: a template
  repository derived from Feedback (it ages separately from Core); rejected: Feedback itself as the
  template (everyone starts from someone else's business logic).
- **A Solution installs without touching Core's `composer.json` or rebuilding Core's front end**
  (owner, 2026-10-07: "for the first author to appear, everything has to be ready and convenient
  from the start"):
  - PHP through the existing composer overlay (`composer.overlay.json`, `tools/composer-overlay.php`);
    a self-hoster on the published images builds a derived image — `FROM` Core's image plus the
    overlay, `composer install`, no Node — from a documented recipe in one command.
  - The UI ships prebuilt: the Solution's package carries ES modules, CSS and a manifest in
    `dist/`, built by the author with FaPost's Vite preset (`@fapost/vite-plugin-solution`, set up by
    the scaffold). The preset externalizes `vue`, `@inertiajs/vue3` and `@fapost/ui`, records the
    kit range the Solution was built against, bundles the Solution's own npm dependencies, and
    turns Tailwind's preflight off so the Solution cannot restyle Core.
  - Core maps the shared modules through an import map to its own chunks (one Vue, one kit),
    resolves Inertia pages named `<solution>::<Page>` from the Solution's manifest, and publishes the
    Solution's assets with a PHP command at install.
  - Compatibility is checked, not hoped for: a Solution whose kit range does not match Core's kit
    is not activated, and the activation screen says why.
  - Rejected: a full rebuild of Core's front end at install (ADR-06's original stance) — every
    install needs Node and a Vite build, a Solution's own npm dependencies have nowhere to be
    declared, and a Solution's build error breaks Core's build. Cost accepted: `@fapost/ui` becomes
    a runtime interface whose semver must hold strictly from its first published version, and
    Tailwind utilities are partly duplicated between Core's and a Solution's CSS. Supersedes the
    build-time-only rule of ADR-06 and ui-foundation's "Vue picked up by a Vite glob" — to be
    recorded in ui-foundation's planned ADR. Plugins still ship no Vue.
- Carried over: the Solution lives in its own repository, installed as a package; no `App\…`
  imports; contract changes go into the Foundation repository.

## Open questions

- Voter identity on the shared board: what identifies a voter from another installation
  (installation id plus a hashed user id?), and whether a customer of a tenant may vote on the
  platform board at all or only staff.
- Moderation of the public board: is a new item public at once or after the FaPost team approves
  it, and what is stripped (tenant names, personal data) before it is shown elsewhere.
- How an installation authenticates to the receiver — registration with a token, rate limits,
  revocation.
- How a Solution is developed against a running Core — a watch build of `dist/` that Core picks up,
  or a dev server Core proxies; the convenience the owner asked for depends on it.
- How customers reach the widget: a mini app extension point, a bot command, or both — the UI
  extension contract has no mini-app point yet.
- Whether the gap record is a document in this repository or issues in the Foundation repository.

## Assumptions left untested

- That the extension points Feedback needs beyond ui-foundation's five — an application in the
  switcher, contextual actions in Core's screens, a slot in the shell, a mini-app point, an event
  that starts a flow — are contract additions, not Feedback-specific hacks; the second Solution
  tests it.
- That the published packages and the local symlinks behave identically — an install from the
  published channel into a clean environment would test it.
