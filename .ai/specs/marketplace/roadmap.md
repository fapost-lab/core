# Roadmap — Marketplace

Destination: the owner sells FaPost products — and later third-party publishers sell theirs — to
self-hosted installations, with licences that decide which versions an installation may
download, run from one console with little manual work.

## Phase 1 — Free catalogue

Goal: products are found and downloaded by installations. Done when: a free Solution published by the
owner appears in an installation's catalogue, filtered by its versions, and installs through the
marketplace's Composer repository.

- [ ] Stack and architecture chosen — in the marketplace's own repository, this spec as input
- [ ] Owner's console and product cards — product types, categories, tags, compatibility read from
      releases (after: Stack and architecture chosen — nothing to build on before it)
- [ ] Composer repository — releases served by version, access by key, no `source` fallback (after:
      Stack and architecture chosen)
- [ ] Catalogue API — what `extension-installer` reads, filtered by an installation's versions (after:
      Owner's console and product cards — the content it serves)
- [ ] FaPost account and organisations — one account, organisations, members, installations (after:
      Stack and architecture chosen)

## Phase 2 — Licences

Goal: an installation downloads exactly what its licence covers. Done when: a licence with an update
period lets an installation download the versions of its period and no later ones, after expiry too.

- [ ] Licence models v1 — free, and paid with an update period; fields for the rest (after: FaPost
      account and organisations — licences belong to organisations)
- [ ] Licences in the repository — the key as Composer auth, installation binding, activation limits,
      dev and staging keys, self-service re-binding (after: Licence models v1; Composer repository)
- [ ] Integrator management — "managed by", bulk purchase, transfer (after: Licences in the repository)
- [ ] Offline licence files — signed, for air-gapped installations (after: Licence models v1)
- [ ] Update check and revocation — versions in the field, revoked versions announced (after: Catalogue
      API)

## Phase 3 — Trust

Goal: what reaches an installation is known to be what was published. Done when: every release is
signed by a key off the servers, and every listed product carries a "tested with" mark for the latest
Core.

- [ ] Release signing — a key outside the marketplace's servers, revocation
- [ ] Compatibility CI — every product installed and booted against each Core release, "tested with"
      marks (after: Composer repository — it installs from there)

## Phase 4 — Money

Goal: the owner earns from paid products. Done when: a buyer pays for a product and gets its licence
without the owner's hands. Lands together with the SaaS shell's billing.

- [ ] fog: payments as the only seller — a merchant of record behind an abstraction; the provider
      depends on the selling entity's jurisdiction, not decided
- [ ] Per-licence watermarking — per-licence `dist.url`, the mark in `dist/` assets, a publisher's
      option (after: Licences in the repository — the licence is in the URL)

## Phase 5 — Third-party publishers

Goal: other authors sell through the marketplace. Done when: a publisher outside FaPost passes
onboarding, publishes a product that passes review, and is paid out.

- [ ] fog: publisher onboarding, review and moderation — what review consists of is open
- [ ] fog: Stripe Connect, the platform's tax registration, revenue share and payouts — depends on the
      jurisdiction and on Phase 4

## Waves

1. Stack and architecture chosen
2. Owner's console and product cards; Composer repository; FaPost account and organisations; Release signing
3. Catalogue API; Licence models v1; Compatibility CI
4. Licences in the repository; Offline licence files; Update check and revocation
5. Integrator management; Per-licence watermarking

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
