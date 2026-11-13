# Marketplace — FaPost's store for Solutions, plugins, connectors and flow templates

Depth: deep — money, licences, other publishers' code reaching every installation, and tax law; most
decisions here are expensive to take back. Written in Core's repository on 2026-11-12 and meant to be
copied into the marketplace's own repository when it is created (jig-idea §11), the way the SaaS shell's
spec was; from then on that copy is the one that moves. Siblings: `extension-installer` (Core's client)
and `fapost-agent` (the host agent).

## Idea

The owner, translated from Russian: "I want to build a marketplace of plugins and Solutions. Nothing
special in it. A developer registers and adds a Solution. A Solution has a name, a description, a
category, maybe tags, in future most likely compatibility, a price or free, and so on. The buyer
registers too, sees purchase history, licence terms, licence renewal. Hence a licence management
module. Think through how to protect products from illegal distribution — maybe access to the
repository by token."

On its purpose: "It is one of the ways to promote FaPost and one of the monetisation channels. My own
benefit comes first, and I must be able to manage this process as easily as possible." On scope: "We are
writing a spec for a new product, so we must lay down everything and lose nothing." On the stack: "I
think not to tie it to my stack but to choose the optimal one for the task."

## Goal and problem

- Who is worse off without this, and how: the owner, who has no channel to put FaPost products in front
  of self-hosted installations and earn from them; later, third-party authors, who can write a Solution
  but cannot sell it; and integrators, who need licences for many clients managed from one place.
- What is true when the work is done: a publisher (first the owner alone, later anyone who passes
  onboarding) lists a product with a licence model of their choosing; a buyer — a company or an
  integrator acting for its clients — gets a licence and the installation downloads exactly the versions
  it covers; the owner runs it all from one console with little manual work.

## Stress test

- Hidden assumptions — "this holds only if …":
  - Protection is possible. False for code: PHP is shipped as source, Core is open, any check can be
    patched out, and obfuscation drives integrators away. It holds only for the channel — who may
    download which versions — plus traceability and an EULA. Decided accordingly.
  - People will renew. Holds only if a licence buys something the installation needs: since expiry only
    stops new versions, the pull is Core itself — every Core release needs product updates, and only a
    live licence brings them.
  - A merchant of record can carry the money. Holds only while the owner is the only seller: Paddle,
    Lemon Squeezy and Freemius forbid marketplaces of third-party sellers in their terms.
- The main trade-off: build the licence server and Composer repository (it is the product's core and the
  rules are unusual) against buying them (Keygen, Anystack, Private Packagist) — chosen: build, with
  Packeton as a reference; payments bought, behind an abstraction.
- The weakest point: demand. A free catalogue, one seller, open source, and nothing stops working on
  expiry — the store may become a downloads page with the costs of a product.
- Failure modes — cause, what breaks, the signal that shows it (independent failure hunt):
  - A malicious or compromised version from a publisher — there is no sandbox, it reads every tenant on
    every installation it reaches; revoking stops downloads, not running code; signal: usually external
    — a leak, a researcher, a client.
  - The signing key leaked — mass substitution of releases; signal: signatures valid on releases nobody
    published.
  - Declared compatibility ranges never tested — the agent installs something broken, or an abandoned
    product freezes its users on an old Core; signal: Core versions in the field stop moving.
  - The repository as a single point of failure for other people's builds — signal: a wave of failed
    builds at one time, 401/5xx in the repository's logs.
  - Licences tied to installation ids meet disaster recovery, moves and clones; integrators share one
    key; signal: "already activated" tickets, one key from many addresses.
  - Watermarks against signatures and caches: a per-licence archive has its own hash, a signature per
    download, Composer caches are useless, and a mark in PHP source is stripped by a script.
  - Money and jurisdiction postponed — monetisation hits the wall of not being able to take money or pay
    publishers legally; a single FaPost account blocked cuts a client off from everything at once.
- Other shapes considered, and why this one: see Decisions.

## Scope and non-goals

- In scope: the catalogue and product cards; product types (Solution, zip plugin, connector, flow
  template); publishers, onboarding, review and trust levels; organisations, members, installations and
  licences; licence models; the Composer repository with licence auth and per-version access; release
  signing and revocation; compatibility CI against Core releases; per-licence watermarking; the catalogue
  and licence API Core reads; payments, payouts and tax; the owner's console; the single FaPost account.
- Not doing: anything inside an installation (`extension-installer`, `fapost-agent`); sales inside SaaS —
  there the operator connects Solutions and grants them through the shell's plans, and a tenant buys
  nothing in the marketplace (owner, 2026-11-13, spec `ai-solution`).

## Decisions

- A separate closed product with its own repository, and its stack chosen for the task, not inherited
  from Core — rejected: inside Core (a fork could pose as the store, every installation would carry its
  code), inside the SaaS shell (self-hosted operators never see it).
- At the start only the owner sells; third-party publishers — registration, review, revenue share,
  payouts — are designed now and built last.
- Product types from day one: Solution, zip plugin, connector, flow template. Flow templates are data,
  not code: the cheapest to review and to try, possibly the best first paid product.
- Product card: name, description, category, tags, product type, compatibility (read from the release's
  `extra.fapost`: Core, Foundation, kit and Vue ranges), price or free, licence model, trust level.
- The licence model is chosen by the publisher when creating the product: per installation or per
  tenant, with an update period or as a subscription. v1 implements two: free, and paid with an update
  period (use forever, versions released in the period downloadable forever). The fields for
  subscription-to-use and a grace period exist, unimplemented. The owner: "maybe it need not be so
  complicated, at least at first."
- The marketplace sells only to self-hosted installations: per installation by default, and revoking
  never breaks a working system. In SaaS the operator connects Solutions and puts them into plans
  (owner, 2026-11-13: "simpler that way") — rejected: a per-tenant marketplace subscription in SaaS,
  the default accepted earlier, because the operator's plans do the same job with one less system.
- Protection is of the channel: a Composer repository where the licence key is the credential and the
  installation id is bound to it; on expiry only versions released after it close (the same as Shopware
  and Craft); an EULA for each paid product, which is not Apache-2.0.
- Activation limits: set by the publisher, default one production installation; dev and staging keys
  free and unlimited (Craft's model); self-service re-binding to a new installation id a limited number
  of times per period. Two simultaneous update checks from different addresses with one id are a signal,
  not a block.
- Per-licence watermarking, a publisher's option, built late: the repository answers Composer's
  `metadata-url` per authenticated licence with its own `dist.url`; the archive is built on the fly,
  cached per licence and version, with its own sha1; no `source` is served (Composer would fall back to
  git); the server checks the licence in the URL against the token. The mark goes into the prebuilt
  `dist/` assets — images, the JS bundle — the owner's own logo trick, not into PHP. The signature covers
  everything except the marked files. Nobody in the PHP ecosystem does per-buyer watermarks today
  (Magento, Nova, Spatie, Shopware, Craft all use a shared archive and a personal token).
- Organisations: members and roles; installations belong to an organisation; licences are bought by an
  organisation and assigned to an installation; a "managed by" relation lets an integrator's
  organisation manage licences owned by a client's organisation; licences can be bought in bulk and
  transferred.
- A single FaPost account across the SaaS cabinet, the marketplace, support and later the publisher
  cabinet. SaaS billing stays in the shell; whether marketplace payments share its process is open.
- Trust levels: "verified by FaPost" (review, signature, compatibility CI) and "community" (automatic
  checks only, an explicit warning on install); the SaaS operator installs only the first — the
  operator's own rule, not the marketplace's. The signing key lives
  outside the marketplace's servers (HSM or offline signing). A revoked version reaches installations
  through their update check.
- Compatibility CI: on every Core release the marketplace installs and boots each listed product against
  it and marks it "tested with 0.x".
- The update check is the heartbeat: installation id and versions, no tenant data, switchable off on the
  installation's side; it shows the owner which Core versions live in the field.
- Licences and the Composer repository are built, Packeton as a reference — rejected: Keygen (it
  advertises a private Composer repository with the licence key as password, though Composer is not
  among its documented distribution engines; but no per-licence watermarking, no organisations with a
  "managed by" relation, and its self-hosted edition is under the Fair Core License), Anystack
  (not self-hostable, not a merchant of record, a share of every sale), Private Packagist for Vendors
  (from €649 a year, no runtime licences, no watermarking) — all leave the unusual rules to us anyway.
- Payments behind an abstraction. While the owner is the only seller: a merchant of record (Paddle or
  Polar) carries VAT. For third-party publishers: Stripe Connect with Stripe Tax, and the platform's own
  tax registration (EU OSS, US states) — in the EU a platform selling electronic services is treated as
  the supplier (Art. 9a of Implementing Regulation 282/2011). Rejected for third parties: Paddle, Lemon
  Squeezy, Freemius — their terms forbid marketplaces.
- The first step is a free catalogue; money comes later and lands together with the SaaS shell's
  billing.

## Open questions

- The jurisdiction of the selling legal entity — decides which payment providers are available at all
  and the tax registrations needed.
- The stack and architecture of the marketplace itself — decided in its own repository, with this spec
  as input.
- The exact revenue share, payout schedule, refunds and disputes for third-party publishers.
- On what terms a SaaS operator installs a third-party product — a per-installation licence is unfair to
  a publisher when one SaaS installation serves thousands of tenants; needed before third-party
  publishers arrive (raised by spec `ai-solution`).
- Whether marketplace payments share the SaaS shell's billing process now that the marketplace sells
  only to self-hosted — decides the payments phase's coupling to the shell.
- What review consists of for third-party code: which automatic checks, who reviews, how long.

## Set aside

- Subscription-to-use with deactivation after a grace period — set aside because v1 licensing is
  minimal; the fields exist.
- unexplored: the publisher cabinet and moderation flow in detail.
- unexplored: search, reviews and ratings, screenshots, a "try before you buy" demo tenant in SaaS.
- unexplored: integration with the SaaS shell beyond the single account.

## Assumptions left untested

- Licence policy v1 (free, and paid with an update period) is enough for the first paid products —
  taken at deep; the first paid product tests it.
- Renewals are driven by Core upgrades needing product updates — taken at deep; the renewal rate tests
  it. The AI Solution (spec `ai-solution`) is the first paid product to test it; new models and
  providers add their own pull towards updates.
- Integrators want to manage clients' licences rather than resell under their own name — taken at deep;
  the first integrator with several clients tests it.
