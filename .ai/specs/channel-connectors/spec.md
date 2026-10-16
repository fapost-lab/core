# Channel connectors as packages

Depth: normal — the Foundation contracts for a channel already exist, so this is not a design from
nothing; but several decisions are real and costly to reverse (the package type, Telegram's fate, how
much of Flow is Telegram-shaped), one question each.

## Idea

Owner, 2026-10-16: "connectors should be shipped as packages (plugins)" — said in a conversation about
keeping Core minimal, alongside moving RAG into a Solution and deciding where the Telegram Mini App
lives. Core gives the channel contract; a connector implements it.

Refined by the owner the same day, after a first draft made connectors runtime zip plugins: "a connector
is still a basic extension of the core; without it the core does not work. So it can — should — be
installed through Composer, probably with the same overlay mechanism as a Solution. Plugins that merely
extend functionality will be zip packages."

And, to make sure the contract is right: "let's build a Viber connector straight away."

## Goal and problem

- Who is worse off without this, and how: anyone who needs a messenger Core does not ship. A new
  provider is a Core change today (`docs/site/extending/channel-drivers.mdx` says so): an integrator
  cannot add one, and the Core team carries every provider's release cycle, API drift and tests. An
  installation that uses only one messenger still carries the code of all of them.
- What is true when the work is done: a messenger is added to an installation by adding a Composer
  package to its image; Core's code contains nothing provider-specific and no closed list of channel
  types; Telegram is one such package, required by Core's distribution, and works exactly as it does
  today; Viber is a second one, optional, built outside Core against the contract before Telegram moves.

## What Viber tests that Telegram does not (paper check, 2026-10-16)

Viber's REST Bot API, mapped onto the contract; algorithm and limits to be confirmed against Viber's
documentation in the connector's task.

- Inbound signature: `X-Viber-Content-Signature`, an HMAC-SHA256 of the raw body keyed by the bot token.
  `IngressSpec` has only `header_equals`; a body-HMAC scheme is needed in Foundation, PHP and the Go
  gateway, with shared golden fixtures. The idempotency key is the body's `message_token`.
- Webhook registration: `set_webhook` with `event_types`; Viber calls the URL back (`event: webhook`) and
  expects 200, which ingress already answers. No separate secret token: the signature uses the bot token.
- Media are sent only by a public URL (picture, video, file); there is no upload and no `file_id`. Core
  must be able to hand a connector a signed, expiring public link to a media asset, and the media uploader
  becomes an optional capability.
- No message editing and no buttons attached to a message bubble: a keyboard lives until the next message.
  Removing a keyboard by editing (`remove_keyboard`, persistent inline buttons) is a capability Viber lacks.
  `tracking_data` carries up to 4 KB of state, not 64 bytes, so `CallbackDataCodec` is Telegram's detail.
- Buttons are a grid (columns and rows, colours) with action types reply, open-url, share-phone,
  location-picker — the neutral model's request for contact and location maps onto them.
- `conversation_started`: the user opened the chat but is not subscribed yet; one welcome message is
  allowed. A new inbound event kind.
- A 24-hour session window: replies inside it are free, bot-initiated messages outside it are billed per
  delivery (bots created since February 2024). Broadcasting and quotas will need to know about the window —
  as they will for WhatsApp.
- No typing indicator: the typing capability really is optional.
- Public link: `viber://pa?chatURI=<uri>`, the URI read from `get_account_info` like Telegram's `getMe`.

## What exists (facts, 2026-10-16)

- Foundation already holds channel-agnostic contracts with string types: `ChannelInterface`,
  `IngressSpec` (data, executed by PHP and the Go gateway), `WebhookRegistrarInterface`,
  `ProviderSenderInterface`, `TypingCapableProviderInterface`, media uploader/downloader, inbound DTOs.
- Core's `ChannelRegistry` is fed by container tags, but drops any definition whose type is not a case of
  the closed `ChannelTypeEnum` (`ChannelRegistry.php:31`); `PlatformEnum` (Contact) is closed too, and
  `ChannelAdapterInterface` lives in Core and returns that enum.
- `CoreRegistrarInterface` has no channel surface; the only implementation is the per-Solution registrar,
  which accepts action handlers only. The manifest schema (`ManifestSchema`) knows `fapost-solution` and
  reserves `fapost-plugin`; `platform:update` discovers and validates Solutions; a custom image adds
  packages through the compose build overlay (`docs/site/self-hosting/docker-compose.mdx`).
- The Go gateway is provider-agnostic: it routes by hash and executes the spec published per platform.
  The landlord `webhook_registry` and the tenant `channels` table are generic; the one Telegram column is
  `channels.telegram_bot_username`.
- Telegram leaks outside its module: `Channel::publicUrl()` (`t.me`), `ChannelFieldRules`,
  `PresentsChannels`, `ChannelForm.vue` (`isTelegram`, `allowed_updates`), `config/services.php` and
  `config/media.php`, loops over `ChannelTypeEnum::cases()` in ingress publishing.
- Flow is Telegram-shaped without naming it: `FlowMessageSender` builds Bot API keyboards
  (`inline_keyboard`, `callback_data`, `request_contact`), `CallbackDataCodec` exists for the 64-byte
  callback limit, `remove_keyboard` and persistent inline buttons. There is no capability model.
- About 95 test files mention Telegram.

## Stress test

The independent failure hunt (a fresh agent given only the idea and the options) ran against the first,
runtime-plugin draft; what still applies to Composer connectors is kept below.

- Hidden assumptions — "this holds only if …":
  - … the declarative `IngressSpec` can describe the next providers. Telegram's header secret fits;
    WhatsApp's GET challenge and VK's confirmation string may not. And if not: those types run without a
    spec and the gateway proxies them to Laravel, which it already does when a spec is absent.
  - … one connector serves every tenant of an installation. A connector is not activated per tenant, so
    which tenant may use which channel type is decided elsewhere (the shell, through the entitlement
    seam). And if a tenant-level switch turns out to be needed: it is a field of the entitlement, not a
    reason to make connectors Solutions.
  - … Core's distribution requiring the Telegram package does not let Core's code lean on it. Only an
    architecture test keeps `App\` free of the connector's namespace.
- The main trade-off: a Composer connector is a deploy — a new messenger means a new image — in exchange
  for Composer resolving its dependencies, migrations and a prebuilt UI being allowed, and no code
  arriving in running processes. For something without which Core cannot talk to anyone, the deploy is
  the right price.
- The weakest point: the neutral message model. Telegram is the only real channel, so the model will be
  drawn around it whatever the intention, and the second connector is what shows where it lies.
- Failure modes — cause, what breaks, the signal that shows it:
  - The neutral message model lies — a flow validates but fails at send time on one provider (button
    count and length, WhatsApp's 24-hour window and templates); provider 4xx in the middle of a dialogue,
    completion rates that differ by channel.
  - Telegram regresses during extraction — 95 test files rewritten against the seams quietly change pinned
    behaviour (callback data, media, rate limit edges) while staying green; support reports of messages
    that "look different". Hence: the seams come first with the old tests untouched, and the tests move
    with the module only after.
  - A stale ingress spec stays in Redis after a connector is removed or a type renamed — deliveries
    verified by the wrong rules or pushed for a type nobody serves; a jump in the gateway's reject rate for
    one type, jobs of an unknown type in `failed_jobs`. Hence: the publisher removes the spec of a type
    that is gone.
  - A connector's settings change shape between versions and nobody migrates stored `config` — the
    settings form cannot render an old channel; a wave of invalid-config errors after an upgrade.
  - Core and the connector drift — Core moves to a Foundation the connector does not accept; Composer
    refuses the build, which is the intended signal, but the published image cannot be cut until the
    connector is released.
- Other shapes considered, and why this one:
  - Connectors as runtime zip plugins — the first draft, rejected by the owner: a connector is a basic
    extension Core cannot work without, so it belongs in the build; runtime zips are for plugins that only
    add functionality.
  - Connectors as Solutions — rejected: per-tenant activation is needless for a transport, and channels
    would show in the Solution catalog.
  - Connectors out of process (a service per connector talking to Core over a queue or HTTP) — a real
    isolation boundary, but a deploy per connector, a network hop on every send and a second runtime to
    operate.
  - Seams only, with Telegram kept in Core — rejected: Core would not become minimal.

## Scope and non-goals

- In scope:
  - The connector package type: `type: fapost-connector` with an `extra.fapost` block, discovered and
    validated by `platform:update`, added to an image through the build overlay.
  - A connector registrar with a channel surface: sender, inbound adapter and ingress spec, webhook
    registrar, media uploader and downloader, translations, a schema of the channel's settings fields, its
    public link, capabilities.
  - Open channel types: a type is a string a registered connector owns, not a case of a closed enum.
  - A channel-neutral outgoing message with connector capabilities, and capability-aware validation and
    builder warnings.
  - Core's channel screens drawn from the connector's schema instead of Telegram branches.
  - Telegram moved onto the seams, then extracted into its own connector package, required by Core.
  - A Viber connector, `fapost/connector-viber`, optional, installed through the overlay, built before
    Telegram moves; and what it needs from Core: a body-HMAC ingress scheme, public media links.
- Not doing:
  - Runtime zip plugins: they stay with `plugin-runtime-contract-idea` and `ecosystem-distribution`.
  - Integrations that are not messaging channels (CRM, payments): they already have action handlers and
    data accessors.
  - Third-party trust, a store, licences.
  - New messengers beyond Telegram and Viber: WhatsApp is the next consumer, a task of its own once the
    contract has survived two channels.
  - Where the Telegram Mini App lives: the task `telegram-mini-app-strategy-idea`.

## Decisions

- A connector is a Composer package of its own type, `fapost-connector`, with an `extra.fapost` manifest
  in the same format as a Solution's, discovered and validated by the same `platform:update` and added to
  an image through the same build overlay; it is not activated per tenant and serves the whole
  installation. Owner, 2026-10-16: a connector is a basic extension without which Core does not work, so
  it is installed at build time; plugins that only add functionality are runtime zips. Rejected: a
  runtime zip plugin (the first draft); a Solution with a channel surface, because per-tenant activation
  is needless for a transport and it would show in the Solution catalog; a separate `plugin.json`-style
  manifest, because it would be a second format with a second validator
  (`adr-20261010-solution-manifest-is-data-in-composer-json`).
- Being deploy-time, a connector may ship tenant migrations for its own prefixed tables and a prebuilt UI
  bundle under the same rules a Solution follows; it may not reach landlord data. This removes the
  first draft's open question about WhatsApp's tables.
- Telegram leaves Core: first Core's seams exist and Telegram, still inside Core, moves onto them (the
  contract proven on the hardest channel we have), then the module moves to its own connector package.
  Rejected: keeping Telegram in Core as the reference, because Core would not become minimal and the
  contract would be proven only on channels nobody uses yet.
- Core's `composer.json` requires the Telegram connector as a distribution dependency, so the published
  image talks to Telegram out of the box; Core's code never references the connector (an architecture
  test), and Core's tests run on a fake channel connector. An installation that wants no Telegram builds
  its own image. Rejected: adding Telegram only through the overlay, because a bare Core from the
  repository would talk to no messenger and the published image would need a second build pipeline.
- This spec covers messaging channels only (ingress, delivery, media, webhook registration). Rejected:
  one "connector" type for channels and integrations (CRM, payments), because integrations already have
  their surface (action handlers, data accessors) and are a different contract.
- Flow describes a message in channel-neutral terms (buttons, reply mode, a request for contact or
  location); a connector declares its capabilities and translates into its provider's format; validation
  and the builder warn when a flow uses what the channel cannot do. Rejected: keeping Telegram's Bot API
  shape as the common format, because every later channel would inherit Telegram's limits (the 64-byte
  callback data among them).
- The contract is proven by a second, real channel before Telegram leaves Core: a Viber connector built as
  an outside package right after the seams, so the gaps it finds are fixed while Telegram is still in Core
  and cheap to change. Rejected: building Viber alongside the Telegram extraction, because every contract
  change Viber forces would then have to be chased into an already extracted package.
- Viber is an optional product connector, `fapost/connector-viber`: supported, but Core does not require
  it; an installation adds it through the build overlay, which proves the outside install path too.
  Rejected: a reference implementation only, because a contract proven on code nobody runs proves less;
  bundling it like Telegram, because Core's distribution should require only what it cannot work without.
- The connector is built and tested on recorded requests and responses; the end-to-end check runs on the
  owner's Viber bot created before February 2024 (the old commercial model), as its own item before the
  package is called done.
- A connector that is no longer installed leaves its channels marked unavailable in the console; their
  inbound deliveries are logged and dropped; their data is not touched; the rest of the platform keeps
  working; `platform:update` names the tenants and channels left without a connector. Rejected:
  deactivating every channel of the type, because deregistering a provider's webhook needs the very
  connector that is gone. (Carried over from the first draft, where the owner chose it for plugins.)

## Open questions

- How a connector's stored `config` is upgraded between versions — a connector's own migration now
  that it may ship them, or a `config_version` with an upgrade function run lazily on read. Decides the
  settings-schema item.
- Where the Telegram Mini App's host goes — answered by `telegram-mini-app-strategy-idea`. A connector may
  now ship a prebuilt bundle, so moving the host with Telegram is possible; whether it is right is that
  task's question.
- `CallbackDataCodec` and persistent inline buttons: Core's neutral concept or Telegram's implementation
  detail — Viber's 4 KB `tracking_data` and lack of message editing point to Telegram's detail. Decided in
  the neutral-message item.
- How broadcasting and quotas account for messages outside a session window (Viber now, WhatsApp next) —
  free replies versus billed bot-initiated sends. Decided once both shapes are in view.
- The registrar: a connector registrar of its own, or the one `CoreRegistrarInterface` with a channel
  surface that each package kind is allowed a subset of. Decided in the package-type item.

## Assumptions left untested

- That Viber's signature is an HMAC-SHA256 of the raw body keyed by the bot token, and the keyboard and
  media limits recalled above — taken from memory; Viber's documentation and the owner's bot test them.
- That the owner's pre-2024 bot keeps working under the old commercial model for the length of this work
  ("until further notice by Viber") — a notice from Viber would test it.

- That `IngressSpec` stretches to the next provider, or that the Laravel fallback is acceptable for those
  it does not — taken at normal depth; writing WhatsApp's spec on paper tests it.
- That no tenant needs a channel type switched off per tenant beyond what the shell's entitlements give —
  taken at normal depth; the first SaaS plan with a channel restriction tests it.
