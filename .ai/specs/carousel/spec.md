# Carousel — cards with pictures, text and buttons, on every channel

Depth: deep — the work changes Foundation's neutral message model, the flow engine, the builder and every
connector, and a mistake in the model is paid for once per channel.

## Idea

The owner, 2026-11-18 (translated from Russian):

> Telegram has no notion of a carousel, but in Viber, in Slack, in Messenger you can make a card with pictures,
> texts and buttons. Done well, it is a nice and useful thing. Maybe for Telegram we should write our own carousel
> with button handling and add it to Core, and extend the connectors.

> It makes sense to have a separate Carousel node, and in its settings choose pictures from the media library and
> the text and buttons for each card — we already have a lot of this. Such a node is very large, and with many
> buttons it is unclear how to build the builder so that it covers everything and stays convenient. Maybe for now
> buttons should only lead to flows (choose an existing one or create a new one).

> A catalogue, news, choosing a plan — I think all of this falls under one definition.

On images: cropping 16:9 to 1:1 will very likely cut away useful content, and if everything on a 16:9 picture is
written in the middle, no designer will make anything sensible of it; either take 2:1 and fill the rest with a
background, or do it the hard way (a picture per channel).

Order: the carousel spec first, then the Viber connector built on its contract.

## Goal and problem

- Who is worse off without this, and how: an assistant author who wants to show a catalogue, news or a choice of
  plans can only send one picture per message, and a message with a picture carries no buttons at all today
  (`FlowMessageSender::toMessagePayload` sets buttons only for `text_with_keyboard`). Channels that have a native
  carousel (Slack, Viber, Messenger, Instagram, WhatsApp, Teams) cannot use it, because Core's neutral message
  model is drawn around Telegram — the weakest point `channel-connectors` already named.
- What is true when the work is done: a flow sends a carousel of up to ten cards — a picture from the media
  library, a title, a text and up to three buttons each — from one node. Channels with a carousel show it
  natively; channels without one get a fallback from Core; a press is resolved against exactly what the person
  saw, also days later.

## Stress test

- Hidden assumptions — "this holds only if …":
  - The neutral model fits every provider only if Core may truncate: provider limits differ (Viber 6 cards,
    Slack and Messenger 3 buttons per card, Messenger titles 80 characters, WhatsApp card bodies 160).
  - Old presses are safe only if they are resolved against what was sent, not against the flow as it is now.
  - Images arrive only if their links outlive the message: Viber, Messenger and WhatsApp fetch them when the
    message is opened, possibly days later.
- The main trade-off: one carousel for every channel (author writes once, Core adapts and truncates) against
  per-channel content (best result per channel, many times the authoring work). Chosen: one carousel, with
  per-channel images as fog.
- The weakest point: the Telegram fallback. Telegram has no carousel; until the paged carousel (phase 4) the
  buttons sit in a separate message from the pictures.
- Failure modes — cause, what breaks, the signal that shows it (independent failure hunt, 2026-11-18, ranked):
  1. An old "Continue here" press resolves against a republished flow — the wrong card or value, no error.
     Signal: selections in logs that do not match the card the person saw. Answered by the per-message snapshot.
  2. A fallback of one message per card multiplies outbound volume — 429s, half-delivered carousels, slow
     broadcasts, muted bots. Signal: Telegram 429 rate, broadcast duration. Answered by the album + keyboard
     fallback (two messages).
  3. Limits checked before variables and translations expand — the provider rejects the whole carousel for some
     languages only. Signal: send errors grouped by locale. Answered by truncation at send and per-translation
     checks.
  4. Signed links expire before a client fetches the picture — empty cards. Signal: 403s on media links long
     after the send. Answered by long-lived links for carousel derivatives.
  5. A late press races the "no response" timeout — two branches, duplicate replies. Answered by resolving
     presses under the session lock, once.
  6. Truncation silently drops options (Viber's 6 cards, its 30 KB request limit, unknown counts in phase 3).
     Signal: per-channel conversion gaps, Viber 400s.
  7. An old "Start flow" press ends a flow the person is in the middle of. Answered by asking before
     interrupting a waiting step.
  8. Making derivatives at send time — CPU stampede on the first broadcast, Telegram showing an old picture
     after a replace. Answered by making them at publish, keyed by content hash.
  9. A waiting node swallows every text — the "please choose" hint traps people. Answered by an optional
     "wrote text" exit.
  10. Provider structure the neutral model cannot express (WhatsApp's identical buttons on every card, 24-hour
      windows, Viber's grid) — warnings that become noise.
  11. Telegram paging carries state per message, `answerCallbackQuery`, edit races, "message is not modified".
  12. The test matrix: channels × button kinds × waiting × static/data × translations.
- Other shapes considered, and why this one:
  - A new content type of `send_message` — rejected: the node is already large, and a carousel needs its own
    exits, editor and limits.
  - Buttons that only start flows (the owner's first thought) — widened: a choice inside one scenario would need
    a flow per button; "Continue here" with one shared exit keeps the graph small instead.
  - A fallback of one message per card — rejected: volume and noise (failure mode 2).
  - Cropping pictures to each provider's ratio — rejected: it cuts away content.

## Scope and non-goals

- In scope:
  - A neutral carousel in Foundation's message model, a `Carousel` channel capability and carousel limits.
  - A Carousel node in the flow engine and builder; snapshots and press resolution; derivatives and long-lived
    links for its pictures; validation; the fallback; native rendering in the Slack and Viber connectors;
    cards built from a list in a variable; a paged carousel in Telegram.
- Not doing:
  - A picture per channel for one card (fog).
  - Rich text in cards: providers' markups are incompatible.
  - Messenger, Instagram, WhatsApp and Teams renderers: each comes with its connector.
  - Choosing the carousel through inline mode in Telegram: the bot cannot push inline results.

## Decisions

- **One node, "Carousel"**, separate from `send_message`. A card is a picture from the media library, a title,
  a text and up to three buttons. Catalogue, news and plan choice are one concept: a set of cards to choose from
  or browse.
- **Three button kinds:**
  - "Start flow" — ends the current flow and starts the chosen one, which receives which card and button were
    pressed;
  - "Open link" — a URL;
  - "Continue here" — every such button leads to the node's one "selected" exit.
  - Each button may carry an author-defined value. The card (index, title), the button label and the value go to
    the node's variable and to a started flow alike.
  - Rejected: only "Start flow" — a choice inside one scenario would need a flow per button.
- **Waiting.** The node waits only when it has a "Continue here" button. Its exits are then "selected",
  "no response" (timeout) and an optional "wrote text" (the text goes to a variable); with "wrote text"
  unconnected a typed reply gets a "please choose" hint, as `send_message` does today. Without "Continue here"
  the node sends and leaves through "next".
- **Snapshot per sent message.** Cards, buttons, values and the flow version are stored when the carousel is
  sent. A press is resolved against the snapshot, under the session lock, once. A "Continue here" press whose node
  is gone from the live version gets "this offer is no longer available".
- **Buttons stay pressable later**, through the persistent-button registry. An old "Start flow" press while the
  session waits for input in another node first asks "Interrupt the current step?" with two buttons; otherwise
  it starts at once. Rejected: always starting silently — it loses a half-filled form.
- **Limits.** The editor allows up to 10 cards and 3 buttons per card. Soft limits: title 80, text 160, button
  label 20 characters; the editor counts and warns, and the builder checks every translation, not only the
  default language. Validation warns where a channel of the assistant allows less (Viber: 6 cards). At send, Core
  truncates surplus cards and every field to the provider's hard limit with "…" instead of failing the message.
- **Text** is plain text with `{{variables}}`, no markup. Card texts and button labels are translated by the same
  mechanism as `send_message` texts.
- **Pictures are never cropped.** Core fits the whole picture into each provider's format and fills the rest with
  a background the author chooses: a colour or a blurred copy of the picture. Authors are advised to draw at 2:1
  with the essentials in the middle. Derivatives are made when the flow is published, keyed by the picture's
  content hash and the provider format; a replaced picture is a new hash, a new derivative and a new Telegram
  upload. Their links stay valid while a published flow uses the picture; other media keeps short links.
  Rejected: cropping to the provider's ratio (cuts content); one picture per channel now (too much authoring).
- **Fallback for channels without a carousel** (Telegram until phase 4): the optional "text above the carousel",
  then an album of the pictures with their captions, then one message whose keyboard groups each card's buttons
  ("Pro → Choose | Details"). Always two or three messages, whatever the card count. Rejected: one message per
  card (volume, rate limits, noise).
- **The text above the carousel** is an optional field of the node; channels with a carousel send it as the
  message's text (WhatsApp requires one).
- **Native rendering:** Slack's `carousel` block (with the fallback for clients that cannot show it), Viber's
  `rich_media`.
- **Viber is built now** (owner, 2026-11-18), reversing the `channel-connectors` decision of 2026-10-26 to park it
  until a customer asks. It follows phase 1 and uses `rich_media` from its first day. End-to-end checks run on the
  owner's Viber bots created before February 2024 (the old, non-commercial terms). The reversal is recorded in
  `channel-connectors` when its task is filed.
- **No epic:** every phase gives people something on its own. The node stays out of the palette until its editor
  is ready.

## Open questions

- How a Telegram paged carousel keeps its state: which page each message shows and the card set behind it,
  given that `callback_data` (64 bytes) is already filled by the session and button ids — phase 4 depends on it.
- What the web widget shows for a carousel — settled with the `web-widget` spec, which deferred messages richer
  than Telegram's.

## Set aside

- A picture per channel type for one card ("a square picture for Viber" over the main one) — set aside because
  fitting with a background covers most cases and per-channel pictures multiply the authoring work.
- unexplored: analytics — which cards are pressed, conversion per card and per channel.
- unexplored: carousels in broadcasts (the `broadcast-flows` draft) — a catalogue of offers in one broadcast.
- unexplored: cards from a Solution or a data source, such as a shop's product catalogue — the continuation of
  phase 3.
- unexplored: list messages (a vertical list with a choice, WhatsApp and Messenger) for long lists without
  pictures.
- unexplored: cards an AI assembles from its answer (the `ai-solution` spec).

## Assumptions left untested

- Messenger's and WhatsApp's 24-hour windows are the connector's concern: a send outside the window takes the
  node's error exit — taken at deep; tested when their connectors are built.
- WhatsApp's rule that every card carries the same button kind and count becomes a validation warning with its
  connector — taken at deep; tested by that connector.
- Fit-with-background pictures look acceptable to authors without a designer — taken at deep; tested by the
  first real catalogue on Viber and Telegram.
- Slack's carousel block renders in the clients people use (it appeared in 2026; older clients need the
  fallback) — taken at deep; tested by the Slack end-to-end check.
