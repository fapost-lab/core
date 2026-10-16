# Roadmap — Channel connectors as packages

Destination: a messenger is added to an installation by adding a `fapost-connector` Composer package to
its image; Core's code contains nothing provider-specific and no closed list of channel types; Telegram is
such a package, required by Core's distribution, and behaves exactly as it does today; Viber is a second,
optional one, built outside Core first.

## Phase 1 — The connector package type

Goal: a package outside Core can be a channel. Done when: a fixture connector, installed through Composer,
is found and validated by `platform:update` and registers a channel type whose sender Core reaches.

- [ ] Connector manifest and discovery — `type: fapost-connector` and its `extra.fapost` schema in
      Foundation's parser; `platform:update` discovers, validates and catalogs connectors beside Solutions,
      with no per-tenant activation; a connector boots for the whole installation
- [ ] Connector registrar with a channel surface — sender, adapter and ingress spec, webhook registrar,
      media uploader and downloader, translations, public link; a connector's own tenant migrations
      (after: Connector manifest and discovery — the registrar is handed to discovered connectors)
- [ ] Open channel types — types are strings owned by a registered connector, `ChannelTypeEnum` and
      `PlatformEnum` no longer close the list; ingress specs are published and removed per registered type;
      channels of a type with no connector show as unavailable and `platform:update` names them (after:
      Connector registrar with a channel surface — the registry is filled through it)

## Phase 2 — Channel seams in Core

Goal: nothing in Core outside Telegram's module knows Telegram. Done when: Telegram, still inside Core, is
reached only through the connector registrar, with its existing tests untouched and green.

- [ ] Telegram on the registrar — Telegram's module registers itself through the connector registrar like
      an outside package would, instead of Core's hard-coded provider list (after: Open channel types —
      it needs the open types to register into)
- [ ] Channel settings from the connector's schema — Core's channel screens and request rules draw a
      connector's declared fields instead of `isTelegram` branches; `telegram_bot_username` moves into
      `channels.config`; provider config and media limits come from the connector (after: Connector
      registrar with a channel surface — the schema is registered on it)
- [ ] Neutral outgoing message — Flow describes buttons, reply mode and requests for contact or location
      without Bot API structures; Telegram's module translates; capabilities declared per connector
      (after: Connector registrar with a channel surface — capabilities are registered on it)
- [ ] Capability-aware validation — validation and the builder warn when a flow uses what its assistant's
      channels cannot do (after: Neutral outgoing message — it checks against the declared capabilities)
- [ ] fog: connector config upgrades between versions — depends on the open question (a connector
      migration or a `config_version`), best answered once a second version of a schema exists

## Phase 3 — Viber, the contract's first outsider

Goal: a channel Core never knew works through the contract alone. Done when: an installation that adds
`fapost/connector-viber` through the overlay talks to a real Viber bot — text, buttons, contact and
location requests, media — with no Viber code in Core.

- [ ] Body-HMAC ingress signatures — a signature scheme over the raw body in `IngressSpec`, executed by PHP
      and the Go gateway, with shared golden fixtures
- [ ] Public media links — Core hands a connector a signed, expiring public URL for a media asset, for
      channels that send media by URL; the media uploader becomes optional
- [ ] Viber connector package — `fapost/connector-viber`: ingress and `conversation_started`, webhook
      registration, text, keyboards, contact and location requests, media by URL, settings schema,
      translations, public link; tested on recorded requests and responses (after: Open channel types,
      Channel settings from the connector's schema, Neutral outgoing message — it is built on them;
      after: Body-HMAC ingress signatures, Public media links — Viber needs both)
- [ ] Viber end to end — the package installed through the overlay against the owner's pre-2024 bot; the
      gaps found go back into the contract before Telegram moves (after: Viber connector package)
- [ ] fog: session windows — how broadcasting and quotas treat bot-initiated messages outside a 24-hour
      window; to be shaped with WhatsApp in view

## Phase 4 — Telegram as a connector

Goal: Core is free of Telegram. Done when: Core's code has no Telegram and its tests pass on a fake
channel connector; an installation upgraded across the change keeps every Telegram channel working with no
data change.

- [ ] Telegram connector package — the module and its tests move to their own repository as
      `fapost-connector`, required by Core's `composer.json`; an architecture test keeps `App\` off its
      namespace; Core's tests run on a fake connector (after: all of Phase 2 — the module moves only once
      nothing outside it knows Telegram; after: Viber end to end — the contract changes Viber forces land
      before Telegram is outside)
- [ ] Upgrade path proven — an installation with live Telegram channels upgraded to a Core without
      Telegram code, rows untouched (type stays `telegram`), webhooks and specs unchanged (after: Telegram
      connector package)
- [ ] Published docs — `docs/site/extending/channel-drivers.mdx` rewritten from "a new provider is a Core
      change" to the connector contract, with Telegram as the reference; the extension model gains the
      connector beside Solution and Plugin (after: Telegram connector package)
- [ ] fog: the Telegram Mini App's host — waits on `telegram-mini-app-strategy-idea`; a connector may ship a
      prebuilt bundle, so the host can move with Telegram if that task says so

## Waves

1. Connector manifest and discovery; Body-HMAC ingress signatures; Public media links
2. Connector registrar with a channel surface
3. Open channel types; Channel settings from the connector's schema; Neutral outgoing message
4. Telegram on the registrar; Capability-aware validation; Viber connector package
5. Viber end to end
6. Telegram connector package
7. Upgrade path proven; Published docs

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
