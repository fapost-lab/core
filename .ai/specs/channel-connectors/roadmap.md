# Roadmap — Channel connectors as packages

Destination: a messenger is added to an installation by adding a `fapost-connector` Composer package to
its image; Core has no closed list of channel types and reaches every channel through the same contracts;
Telegram stays built into Core on those contracts and behaves exactly as it does today; Viber is the first
outside connector, optional, and proves the contract.

Epic: epic/channel-connectors
Release: minor

## Phase 1 — The connector package type

Goal: a package outside Core can be a channel. Done when: a fixture connector, installed through Composer,
is found and validated by `platform:update` and registers a channel type whose sender Core reaches.

- [x] `connector-manifest-discovery` — Connector manifest and discovery: `type: fapost-connector` and its `extra.fapost` schema in
      Foundation's parser; `platform:update` discovers, validates and catalogs connectors beside Solutions,
      with no per-tenant activation; a connector boots for the whole installation
- [x] `connector-channel-registrar` — Connector registrar with a channel surface: sender, adapter and ingress spec, webhook registrar,
      media uploader and downloader, translations, public link; a connector's own tenant migrations
      (after: Connector manifest and discovery — the registrar is handed to discovered connectors)
- [x] `open-channel-types` — Open channel types: types are strings owned by a registered connector, `ChannelTypeEnum` and
      `PlatformEnum` no longer close the list; ingress specs are published and removed per registered type;
      channels of a type with no connector show as unavailable and `platform:update` names them (after:
      Connector registrar with a channel surface — the registry is filled through it)

## Phase 2 — Channel seams in Core

Goal: nothing in Core outside Telegram's module knows Telegram. Done when: Telegram, still inside Core, is
reached only through the connector registrar, with its existing tests untouched and green.

- [x] `telegram-on-registrar` — Telegram on the registrar: Telegram's module registers itself through the connector registrar like
      an outside package would, instead of Core's hard-coded provider list (after: Open channel types —
      it needs the open types to register into)
- [x] `channel-settings-schema` — Channel settings from the connector's schema: Core's channel screens and request rules draw a
      connector's declared fields instead of `isTelegram` branches; `telegram_bot_username` moves into
      `channels.config`; provider config and media limits come from the connector (after: Connector
      registrar with a channel surface — the schema is registered on it)
- [x] `neutral-outgoing-message` — Neutral outgoing message: Flow describes buttons, reply mode and requests for contact or location
      without Bot API structures; Telegram's module translates; capabilities declared per connector
      (after: Connector registrar with a channel surface — capabilities are registered on it)
- [ ] `capability-aware-validation` — Capability-aware validation: validation and the builder warn when a flow uses what its assistant's
      channels cannot do (after: Neutral outgoing message — it checks against the declared capabilities)
- [x] `outbound-message-without-token` — Outbound message without the token: the `OutboundMessage` envelope a
      connector's sender receives no longer carries the channel token into queued payloads; the sender gets it
      when the job runs (after: Telegram on the registrar — the sender contract is settled there)
- [ ] fog: connector config upgrades between versions — depends on the open question (a connector
      migration or a `config_version`), best answered once a second version of a schema exists

## Phase 3 — Viber, the contract's first outsider

Goal: a channel Core never knew works through the contract alone. Done when: an installation that adds
`fapost/connector-viber` through the overlay talks to a real Viber bot — text, buttons, contact and
location requests, media — with no Viber code in Core.

- [x] `ingress-hmac-body-signature` — Body-HMAC ingress signatures: a signature scheme over the raw body in `IngressSpec`, executed by PHP
      and the Go gateway, with shared golden fixtures
- [x] `public-media-links` — Public media links: Core hands a connector a signed, expiring public URL for a media asset, for
      channels that send media by URL; the media uploader becomes optional
- [x] `connector-managed-settings` — Managed settings from a connector: a connector hands Core the values it reads
      from the provider (the managed fields of its settings schema) and Core stores them, so no package writes
      `channels` (after: Channel settings from the connector's schema — it fills that schema's managed fields)
- [ ] Viber connector package — `fapost/connector-viber`: ingress and `conversation_started`, webhook
      registration, text, keyboards, contact and location requests, media by URL, settings schema,
      translations, public link; tested on recorded requests and responses (after: Open channel types,
      Channel settings from the connector's schema, Neutral outgoing message — it is built on them;
      after: Body-HMAC ingress signatures, Public media links — Viber needs both; after: Managed settings from a
      connector — its account URI comes from the provider)
- [ ] Button limits — connectors declare their button limits (buttons per message and per row, label
      length) beside the capability flags, and flow validation warns when a flow exceeds them (after:
      Capability-aware validation — it adds limits to the same check; built with the Viber connector package,
      so the contract is shaped by two channels' limits, not Telegram's alone)
- [ ] Viber end to end — the package installed through the overlay against the owner's pre-2024 bot; the
      gaps found go back into the contract (after: Viber connector package)
- [ ] fog: session windows — how broadcasting and quotas treat bot-initiated messages outside a 24-hour
      window; to be shaped with WhatsApp in view

## Phase 4 — The contract written down

Goal: someone outside the project can write a connector. Done when: the published docs describe the
connector contract with Telegram (built in) and Viber (a package) as references.

- [ ] Published docs — `docs/site/extending/channel-drivers.mdx` rewritten from "a new provider is a Core
      change" to the connector contract, with Telegram and Viber as references (after: Viber end to end —
      the docs describe the contract the outsider proved)
- [ ] fog: the Telegram Mini App's host — waits on `telegram-mini-app-strategy-idea`; Telegram stays in
      Core, so its host stays there unless that task decides otherwise

## Waves

1. connector-manifest-discovery; ingress-hmac-body-signature; public-media-links
2. connector-channel-registrar
3. open-channel-types; channel-settings-schema; neutral-outgoing-message
4. telegram-on-registrar; capability-aware-validation; connector-managed-settings; outbound-message-without-token
5. Viber connector package; Button limits
6. Viber end to end
7. Published docs

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
