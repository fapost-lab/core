# Roadmap — Web widget

Destination: a tenant activates the web widget Solution, styles it to its site in its own languages, lists
its contact channels, pastes one snippet once, and visitors either open a channel of their choice or chat
with the tenant's assistant in the widget — the same flows that answer in Telegram.

## Phase 1 — The launcher

Goal: a site shows the tenant's contact channels behind a branded icon. Done when: a tenant activates the
widget, sets it up through its wizard, pastes the snippet into a test site, and a visitor on a phone and a
desktop opens Telegram, Viber or email from it — and a colour changed in the admin shows on the site
without touching the snippet.

- [ ] Launcher on a site — the widget Solution (its own public repository, Apache-2.0) with a settings
      page for channel items (presets for popular channels and custom items, ordered, switched on and
      off), colours and position; the stable loader snippet carrying the widget's key; the tailored script
      written as a versioned static file on every save and served without PHP from the installation's
      stable address; the launcher in a Shadow DOM, mounted once per page whatever the site's router does;
      allowed domains; install notes for CSP, Google Tag Manager, Tilda, Wix and WordPress caches. Needs a
      new seam: a Solution publishes a static asset at the installation's stable address (Foundation and
      Core)
- [ ] Logo — upload in the settings, kept in the tenant's media and counted against its storage quota,
      published as a static asset beside the script or inlined when small. Needs a Foundation contract for a
      Solution to store a file in the tenant's media (after: Launcher on a site — the settings page and the
      public route exist)
- [ ] The tenant's channels in the list — the tenant's channels with their connectors' public links join
      the list; one connected later arrives switched off, as a suggestion. Needs a Foundation read
      contract for a Solution to list the tenant's channels and their public links (after: Launcher on a
      site — the list it joins)
- [ ] Languages — every visitor-facing text in the tenant's content languages, built-in strings with
      defaults in every supported locale, the visitor's language from the widget's switch, the browser or the
      site's `<html lang>`, or pinned by the tenant per site (after: Launcher on a site — the texts it translates)
- [ ] Switching off — deactivating the widget replaces the published script with an empty stub; in the
      chat phase it also pauses the tenant's `web` channels through a Core seam, keeping their data
      (after: Launcher on a site — what it takes down)
- [ ] Setup wizard — the widget's own block of the onboarding wizard the first time its page opens:
      style, channels, the snippet; settles the `onboarding-extension-steps` contract with the widget as
      its first consumer (after: Launcher on a site — the settings the steps walk through)
- [ ] On FaPost's own site — the launcher on the project's site, as the first real installation; when it
      is published there is the owner's call (after: Logo, The tenant's channels in the list, Languages —
      what a public site needs)

## Phase 2 — The chat

Goal: a visitor talks to the tenant's assistant in the widget. Done when: on a test site, a visitor
writes, gets the assistant's answer with buttons, leaves a phone through the contact form, returns the
next day and sees the conversation — with Reverb on and with it off — and the operator answers the same
visitor from the inbox.

- [ ] Web connector — `fapost/connector-web` (its own public repository): channel type `web`; inbound from
      the widget through a public endpoint with a rate limit per IP and per visitor and an `Origin` check;
      the visitor token created at the first message; a contact per visitor through the inbound gate;
      delivery by polling; its inbound on a queue that one tenant's flood cannot fill for every tenant; the
      contact form's phone marked unverified; capabilities as Telegram's (inline and reply buttons, removing buttons, a
      contact form, location through the browser, media by public link, typing); a settings schema;
      tested on recorded requests
- [ ] Channel requirements in the manifest — a Solution names the channel types it needs; `platform:update`
      and the activation screen say so when the image lacks the connector (Foundation and Core)
- [ ] Chat in the widget — the chat window in the launcher's Shadow DOM, opening straight away when it is
      the only item, the other channels as buttons beside it; the notice and the optional required consent;
      the conversation restored on a return visit, the visitor token able to fetch only what the visitor may
      see; a draft and a half-filled form surviving a page change; the wizard step that sends the tenant to create a `web`
      channel; the Solution requires the connector (after: Web connector — the transport; after: Channel
      requirements in the manifest — the Solution declares `web`; after: Languages, Setup wizard — the chat
      adds to both)
- [ ] Instant replies — Reverb when the installation has it on: a public channel per visitor authorised by
      the signed visitor token; polling stays the fallback (after: Chat in the widget — what it speeds up)
- [ ] The demo on FaPost's own site — a visitor tries an assistant without a Telegram bot; the path
      `first-contact-path` was missing (after: Chat in the widget, On FaPost's own site)
- [ ] fog: bot challenge — Turnstile, hCaptcha or a self-hostable proof of work, required in SaaS or not;
      shaped once the chat sees real traffic

## Waves

1. Launcher on a site; Web connector; Channel requirements in the manifest
2. Logo; The tenant's channels in the list; Languages; Setup wizard; Switching off
3. On FaPost's own site; Chat in the widget
4. Instant replies; The demo on FaPost's own site

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
