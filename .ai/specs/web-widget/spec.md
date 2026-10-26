# Web widget — a website launcher and chat as a Solution

Depth: normal — the shape is clear and fits the Solution and connector contracts, but several decisions
are costly to reverse: a public, unauthenticated endpoint on every tenant, how anonymous visitors count
against quotas, and what the manifest learns about dependencies on channels.

## Idea

Owner, 2026-10-26, after the web chat came up as a rejected way to prove the connector contract (its
protocol is ours, so it proves nothing about an outside API): "You mentioned a great option I wanted to
build, but later — the web widget. My idea is to make it a Solution. So that it has its own settings page,
where you can change the colours to match the site, the logo and so on. Besides that, the ability to
connect other contact channels. Like: there is an icon on the site, a click on it unfolds it, and there
are links to Telegram, Viber, email, and the widget itself among them. Or the widget itself, when it
opens (then it opens straight away), has buttons to the other contact channels next to it. And the
channels themselves are configured inside the Solution — choosing an icon and a link (or we make presets
for popular channels)."

On how the chat fits, when told a channel cannot be a Solution: "Solutions have their own dependencies,
and in the web widget Solution we can name the web channel." And: "A Solution is an independent
solution, it cannot be installed in parts, so its dependencies are its direct components. And when the
Solution's page opens, we already have the wizard mechanism for its settings, where we will send the
person to create a channel."

## Goal and problem

- Who is worse off without this, and how: a tenant whose customers arrive at its website. Today FaPost
  reaches them only if they already found the Telegram bot; the site has no way to show the business's
  contact channels or to let a visitor talk to the assistant on the spot.
- What is true when the work is done: a tenant installs the widget Solution, styles it to the site in its
  settings page, lists its contact channels (from presets or by hand), pastes one snippet into the site,
  and visitors either open a channel of their choice or chat with the tenant's assistant right in the
  widget — the same flows that answer in Telegram.
- Who it is for (owner, 2026-10-26: "I would consider all three; even for testing we need the third, and
  the first two are our consumers either way"):
  - small and medium businesses with a site, whose customers already write to them in messengers;
  - corporate sites, where branding, allowed domains and consent to personal-data processing weigh more;
  - FaPost's own site and demo — the first place the widget runs, and a way to try an assistant without a
    Telegram bot (the weak point named in `first-contact-path`).

## What exists (facts, 2026-10-26)

- Setup wizard: Core's onboarding wizard (`app/Domains/Onboarding`) holds Core's steps, one of them
  `ChannelStep` with an inline channel form (`console.admin.onboarding.channel.create`). A package cannot
  add steps yet: `onboarding-extension-steps` designs that contract and waits for a first real consumer
  (its D8), with a package's block running the first time its page is opened (its D14). The widget can be
  that consumer.
- A Solution's console UI: a prebuilt bundle (ADR `20261014-solution-console-ui`), settings tabs and its own
  application through `ConsoleExtensionsInterface` (`@experimental`); its routes live on the panel domain
  under `apps/<id>`, behind staff authentication. There is no contract for a Solution's public,
  unauthenticated or cross-origin routes or assets, and no CORS configuration in Core.
- Public routes per tenant host exist only in Core: signed media links (no session), the Telegram Mini App
  API, the webhook endpoint.
- Manifest: no field for a dependency on a channel type or another package — only Composer `require`. A
  connector's manifest declares its `channels`; `platform:update` flags a declared type that was never
  registered and tenants whose channels have no connector.
- Channel public links: `ChannelProfileInterface::publicLink()` per connector (`t.me/<username>` for
  Telegram); Core reads them through `ChannelPublicLinks`. A Solution has no read contract for a tenant's
  channels or their links.
- Reverb is installed and off by default (screens poll); its two private channels are for staff; there are
  no public or presence channels and no authentication for anonymous visitors.
- Inbound gate: `InboundContactGate` admits by `monthly_active_contacts`, keyed by tenant, channel type and
  the provider's user id, before the contact is created — each anonymous visitor would be a new active
  contact.
- Media: no Foundation contract for a Solution to upload or store a file such as a logo; Core's upload UI
  is not reachable from a Solution's bundle.

## Stress test

Independent failure hunt, 2026-10-26: a fresh agent given only the idea and the decisions, ranked by
likelihood times cost. Findings that change a decision are marked → and taken up in Decisions or Open
questions.

1. Anonymous visitors burn `monthly_active_contacts`: Safari's ITP wipes script-written storage after seven
   days, private tabs and other subdomains start a fresh identity, and scripts mint contacts at will. The
   tenant hits its quota mid-month and the gate starts refusing real Telegram customers too; history splits
   across duplicate contacts. Signal: active contacts rising much faster than conversations. →
2. The public endpoint is a flooding target on every tenant host: per-IP limits hurt carrier and office
   networks that share one IP, botnets rotate IPs, and one tenant's flood eats queue capacity every tenant
   shares. Signal: a cross-tenant queue backlog, 429s from a few networks. →
3. Every page view on every customer site hits PHP if the tailored script is rendered per request: load
   follows the tenants' web traffic, not their conversations, and one popular site or campaign slows the
   admin and Telegram replies for everyone; polling multiplies it. Signal: the script route's rate tracking
   site traffic, Telegram webhooks slowing at the same moment. →
4. Caches serve stale or wrong scripts: WordPress caching plugins and Cloudflare Rocket Loader combine,
   defer or cache the loader for days, and a shared cache without the host in its key mixes tenants up.
   Signal: "I changed the colour and nothing happened". →
5. The snippet is tied to the tenant's host: a renamed tenant, a custom domain, a changed `APP_URL` or an
   ended trial breaks the widget on every site silently, and CSP allowlists go stale. Signal: loader 404s
   per host after a domain change. →
6. Switching the Solution off does not switch the chat off: the inbound endpoint belongs to the
   installation-wide connector, so cached scripts and bots keep sending, using quota and running flows; a
   tenant who deletes or skips the `web` channel gets a widget that cannot send. Signal: inbound `web`
   messages for tenants with the Solution inactive. →
7. Site builders and consent tools keep the widget from mounting: Wix's HTML embed is a sandboxed frame,
   Tilda and Google Tag Manager change where the code runs, and Cookiebot or OneTrust block unknown scripts
   until consent. Signal: loader hits far below page views; "works on my machine".
8. SPAs and multi-page sites break its life cycle: hydration removes foreign nodes, a snippet inside a
   component runs again on every route, and a multi-page site reloads the chat on every click, losing typed
   text and a half-filled form. Signal: two launchers, chats ending right after a navigation.
9. The page leaks through Shadow DOM (inherited font and colour, `rem`, z-index, a `transform` breaking
   `position: fixed`), iOS Safari adds the soft keyboard, `100vh` and input zoom, and a strict CSP blocks
   inline styles and must also allow Reverb's `wss:` endpoint. Signal: screenshot tickets, CSP reports.
10. The visitor token is a bearer credential in storage every third-party script on the site can read:
    whoever reads it reads the conversation, including a phone, an email and a location. Costly for FaPost
    as processor. →
11. "No consent before the first message" does not hold in full: loading the script sends the visitor's IP
    and user agent to the tenant's host on every page view (German courts treated the same transfer to
    Google Fonts as personal data), and an optional bot challenge adds a third party. Signal: DPA
    questionnaires stalling corporate deals. →
12. Telegram flows assume a verified, stable identity: Telegram's contact request returns a verified phone,
    the web form returns whatever was typed, so a flow that matches an order or a booking by phone hands it
    to whoever types someone else's number. Signal: customers seeing someone else's order. →
13. The wrong language reaches visitors: `<html lang>` is often wrong (WordPress and Tilda default to `en`),
    so the notice and the consent come out in the wrong language and the consent is not informed. Signal:
    contacts with `en` far above the site's audience. →

## Scope and non-goals

- In scope:
  - The widget Solution: settings, branding, the managed list of channels, languages, consent, its wizard
    block, the snippet and the published script; the launcher and the chat window.
  - The `fapost/connector-web` channel the Solution requires.
  - The Foundation and Core seams they need: a Solution's static public asset, file storage in the
    tenant's media, reading the tenant's channels and links, wizard steps from a package
    (`onboarding-extension-steps`), channel requirements in the manifest, pausing a Solution's channels.
- Not doing:
  - Messages richer than Telegram's (cards, carousels, multi-field forms) — Core's neutral message model
    waits for demand.
  - A separate quota for web visitors.
  - Operator features beyond the existing inbox (presence, canned answers, routing to an agent).
  - Analytics of the widget (opens, clicks per channel) — a later item once the widget runs.

## Decisions

- One Solution, built in phases: the launcher first, the chat second (owner, 2026-10-26: "these are still
  phases of one Solution — launcher, then chat"). The launcher builds the contracts a Solution needs to live
  on someone else's site without the risk of inbound messages; the chat adds only the public inbound
  endpoint, anonymous visitors and their quota. Rejected: the chat first, because the first delivery would
  carry every risk at once; both in one phase, because nothing would reach users until all of it is done.
- The snippet the tenant pastes is a stable loader and is pasted once; it pulls a script already tailored
  — colours, design, logo, channels — from the tenant's settings in the admin (owner, 2026-10-26: "the
  snippet will pull an already adapted script with the colours, design and logo from our admin"). So the
  tailored script is served from a public URL on the tenant's host and reflects a settings change without
  touching the site; it is cached and revalidated by the settings' version. This needs a new seam: a
  Solution's public routes on the tenant's host, on a Core-owned stack with no session and no cookies and
  with a rate limit; the Solution writes only the handler. The chat and any later public Solution (forms,
  polls) use the same seam. Rejected: the settings baked into the snippet when saved, because every change
  would mean pasting the snippet again; the whole widget in an iframe on the tenant's host, because a
  floating launcher in a frame fights the site over size, clicks and mobile layout (the chat window may
  still be a frame — decided in the chat phase).
  **Amended after the failure hunt (owner, 2026-10-26):** the tailored script is not rendered per request.
  When the settings are saved, the Solution writes a ready JS file, versioned in its name, under a path
  keyed by the widget's key; the web server or a CDN serves it with no PHP, and the loader reads a tiny
  version file with a short cache. Load then follows settings changes, not the sites' traffic, and a cache
  cannot mix tenants because the key is in the path. The snippet carries the widget's immutable key and
  loads from a stable address of the installation (its base domain or a CDN), not the tenant's host, so a
  renamed tenant, a custom domain or a changed `APP_URL` breaks no site, and a site's CSP allows one
  domain that does not move. So the launcher needs no public PHP route at all: it needs a seam for a
  Solution to publish a static asset at the installation's stable address. The chat's public endpoint
  belongs to the `web` connector. Rejected: PHP with HTTP caching, because every cache miss reaches PHP and
  correctness rests on someone else's cache honouring the headers; the snippet on the tenant's host,
  because every address change means pasting it again on every site.
- The launcher's list draws on three sources in one managed list: the tenant's own channels, with the
  link from the connector's public link (a connected bot shows up without typing its address, and follows
  a changed username); presets for popular channels (an icon and a link template — the tenant types only
  the username or number); and custom items (an icon and a link). The tenant orders the items, switches
  each on or off and can drop what the automation offers (owner, 2026-10-26: "(a), if there is proper
  management and I can refuse what the automation imposes"). This needs a Foundation read contract for a
  Solution to list the tenant's channels and their public links. Rejected: presets and custom items only,
  because a connected bot would be typed by hand and go stale when its username changes; the tenant's
  channels only, because a manager's own Viber or an email address is half of the launcher's value.
- The logo lives in the tenant's media, written through a new Foundation contract that lets a Solution
  store a file (owner, 2026-10-26). It counts against the tenant's storage quota and moves with the rest of
  the tenant's media (`tenant-media-storage`); the widget's public route serves it, since Core's signed
  media links expire, or a small logo is inlined into the tailored script. Rejected: a logo by URL only,
  because it is awkward and depends on someone else's hosting; a Solution's own disk, because it escapes the
  quota and the storage moves and makes a second way to keep files.
- The chat's transport is the channel type `web` from a connector package, `fapost/connector-web`, which
  the widget Solution requires in its `composer.json`: a Solution cannot be installed in parts, so its
  dependencies are its components (owner, 2026-10-26). The manifest learns to name the channel types a
  Solution needs, so `platform:update` and the activation screen say so when an image was built without the
  connector. Rejected: the chat inside the Solution itself, because a channel serves the whole installation
  and is reached through the connector contract (`channel-connectors`), while a Solution is switched on per
  tenant.
- Switching the widget Solution off pauses the tenant's `web` channels (owner, 2026-10-26): their inbound
  answers "unavailable", the conversations and contacts stay, and the published script is replaced with an
  empty stub so the widget disappears from the sites; switching it on resumes them. Needs a Core seam for
  a Solution to pause and resume its channels. Rejected: leaving the channel alone, because cached scripts
  and bots would keep spending quota and running flows until the tenant noticed.
- Setup runs as the widget's own block of the onboarding wizard, the first time its page opens — style,
  channels, the snippet, and in the chat phase a step that sends the tenant to create a `web` channel
  (owner, 2026-10-26). The widget is the first consumer `onboarding-extension-steps` waits for, so that
  contract is settled against it.
- A web visitor is an ordinary active contact, created at the first message (opening the widget costs
  nothing, since the inbound gate admits messages), behind layered defences: a rate limit per IP and per
  visitor, an `Origin` check against the tenant's allowed domains (stops other sites, not scripts that
  forge the header), and an optional bot challenge the tenant switches on (owner, 2026-10-26: "rather
  (a)"). A visitor is recognised on a return visit by a random, server-signed visitor token kept in the
  site's storage. Browser fingerprinting is not used, neither as identity nor as the bot check: headless
  browsers forge it, and under GDPR and ePrivacy (and the Ukrainian law moving the same way) it is personal
  data that needs consent — a bot-challenge service makes that judgement on its side and hands back only a
  verdict. Rejected: a separate "web visitors" limit, because plans and quotas would gain an entity to sell
  and explain; a visitor counted only once identified, because guest conversations cost resources too and a
  flow with a guest who is not a contact is a new model in Core.
  Kept after the failure hunt (owner, 2026-10-26: no separate cap for the `web` channel): a flood of
  anonymous visitors draws on the same monthly active contacts as every other channel, and can push the
  gate into refusing real Telegram customers. Risk accepted; the defences above and the bot challenge carry
  it, and the signal is active contacts from `web` rising much faster than its conversations. Rejected: a
  monthly cap of new contacts through `web` set by the tenant, with the chat folding into the launcher's
  links when it is reached.
- Replies reach the visitor through Reverb when the installation has it on, and by polling otherwise
  (owner, 2026-10-26): the `web` connector publishes to a public channel per visitor, authorised by the
  signed visitor token rather than a session; without Reverb the widget polls every few seconds while open
  and less often while folded. A self-hosted installation gets a working chat out of the box, Reverb makes
  it instant, and `installation-health` already shows Reverb's state. Cost accepted: two delivery paths,
  both tested. Rejected: Reverb required for the chat, because an installation without it could not switch
  the chat on; long polling or SSE only, because each waiting visitor would hold a PHP worker.
- The `web` channel declares nearly everything Telegram does (owner, 2026-10-26): inline and reply buttons,
  removing buttons after a press, a request for contact as a small phone-and-email form in the chat, a
  request for location through the browser's Geolocation API with the visitor's permission, media by public
  link, a typing indicator. A flow written for Telegram works in the widget unchanged — the point of "the
  same flows" — and capability-aware validation warns about the rest. Rejected: text, buttons and media only
  in a first version, because flows that collect a phone number would stop half-way in the widget; richer
  than Telegram (cards, carousels, multi-field forms), because it widens Core's neutral message model and
  waits for demand.
- The whole widget — launcher button, channel panel and chat window — renders in a Shadow DOM on the
  site's own page (owner, 2026-10-26): the site's styles and the widget's do not leak into each other, the
  chat window goes full screen on a phone without frame sizing, and the visitor token sits in the site's
  own storage rather than a third-party frame's partitioned one, which Safari clears aggressively. The site
  belongs to the tenant, so the conversation needs no isolation from its scripts. A site with a strict CSP
  allows the tenant's host in `script-src` and `connect-src`. Rejected: the chat window in an iframe on the
  tenant's host (the large live-chat products' shape), because `postMessage` plumbing, frame sizing on
  phones and partitioned storage buy an isolation the tenant's own site does not need; everything in an
  iframe, already rejected for the launcher.
- Consent: a notice and an optional required consent (owner, 2026-10-26). The visitor token is created at
  the first message, not on page load, so the widget stores nothing before a conversation starts and needs
  no cookie banner for its own storage; storage after the visitor starts a chat is strictly necessary under
  ePrivacy. Loading the script still sends the visitor's IP address and user agent to the installation on
  every page view — the transfer courts have treated as personal data — so the published docs say so, the
  widget is offered to consent managers as a functional category, and FaPost's processing terms cover it. Before
  the first message the widget shows a line with a link to the tenant's privacy policy, both set in the
  settings; a "require consent" switch adds a checkbox without which nothing is sent, for corporate sites.
  The launcher's links collect no data at all. The tenant is the controller, FaPost the processor.
  Rejected: a notice only, because the corporate customer asks for the checkbox first; nothing, leaving it
  all to the tenant, because in SaaS it is a risk for FaPost as processor and it fails corporate sites.
- Every visitor-facing text of the widget — the launcher's labels, channel items, the notice, the consent
  text, the chat's own strings — is content, translated into the tenant's content languages the same way
  as an assistant's messages, not admin UI copy (owner, 2026-10-26: "we must support the tenant's
  languages, i.e. the translation of all texts and notices, as for the assistant"; convention
  `multilingual`). Stored as per-language values in the settings; built-in strings ship with defaults in
  every supported locale and the tenant may override them. The visitor's language is the one they pick in
  the widget, else the browser's languages, else the site's `<html lang>` (often a builder's default `en`,
  so it comes after the browser), each matched against the tenant's content languages, else the
  assistant's default — and the tenant may pin one language per site; in the chat it is handed to Core's language resolution
  as the contact's language, as Telegram's `language_code` is today.

## Open questions

- An unverified contact from the web: Telegram's contact request returns a verified phone, the web form
  whatever was typed. Whether Core's shared contact carries a `verified` mark that flows and Solutions can
  test, and how the builder warns a flow that matches records by phone — decided in the web connector's
  item; until then the web form's phone is marked unverified in the contact's data.
- The visitor token is a bearer credential readable by every script on the site: what it may fetch — the
  history restored on a return visit without the contact details collected, a lifetime, rotation — decided
  in the chat item.
- Whether inbound from `web` runs on a queue of its own, so one tenant's flood cannot delay every tenant's
  Telegram replies — decided in the web connector's item against `conventions/queues`.

- Whether `onboarding-extension-steps` is built as part of this spec's first phase or as that spec's own
  phase with the widget as its consumer — decides who files the tasks; the contract is the same.
- Which bot-challenge service, and whether one is required in SaaS — Cloudflare Turnstile, hCaptcha or a
  self-hostable proof of work; a third-party script on the tenant's site has its own consent question.
  Decided in the chat phase.
- How a Solution reads the tenant's content languages and resolves a text for a visitor — a Foundation
  contract over `LanguageResolverInterface` and the tenant's language settings, or the tailored script
  carrying every language and choosing in the browser. Decides one of the launcher's contracts.

## Assumptions left untested

- A channel the tenant connects after setting the launcher up arrives in the list switched off, as a
  suggestion, so nothing appears on the site that the tenant did not choose — taken at normal depth as the
  reading of "refuse what the automation imposes"; the first tenant who expects it on by default tests it.
