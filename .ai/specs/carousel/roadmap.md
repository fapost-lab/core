# Roadmap — Carousel

Destination: a flow sends a carousel of picture cards with buttons from one node; Slack and Viber show it
natively, Telegram pages through it in one message, other channels get a fallback, and every press resolves
against what the person saw.

## Phase 1 — A carousel from the node, on every channel

Goal: an author builds a carousel in the builder and it reaches people on Telegram and Slack. Done when: a
catalogue of five cards with pictures from the media library works in Telegram (fallback) and Slack (native),
with all three button kinds.

- [ ] Carousel contract — the neutral carousel in Foundation's message model (cards, buttons with kind and
      value, text above), the `Carousel` capability and carousel limits on `ChannelCapabilities`; a Foundation
      release
- [ ] Carousel node — the node in the engine: button kinds, waiting and its exits, the per-message snapshot,
      press resolution under the session lock, persistent buttons, "Interrupt the current step?", translations,
      truncation to provider limits at send (after: Carousel contract — the node sends the contract's model)
- [ ] Carousel pictures — derivatives fitted with a background, made at publish and keyed by content hash, with
      links that stay valid while a published flow uses them (after: Carousel contract — the provider formats
      come from the channel's declaration)
- [ ] Carousel editor — the compact node on the canvas, the wide card-strip panel with preview, reordering,
      counters and limits, background choice; validation of capabilities, limits and every translation; the node
      enters the palette (after: Carousel node — the editor edits the node's schema)
- [ ] Carousel fallback — text above, an album with captions and one grouped keyboard, for every channel without
      the capability; Telegram (after: Carousel node — the fallback renders what the node sends)
- [ ] Slack carousel — `fapost/connector-slack` renders the `carousel` block and its presses (after: Carousel
      contract — the connector implements the capability)
- [ ] Carousel docs — the node in the user guide, the contract in Channel contracts and Connectors (after:
      Carousel editor — the guide describes the finished editor)

## Phase 2 — Viber

Goal: Viber is a connector, with the carousel native from its first day. Done when: a Viber bot created before
February 2024 receives text, buttons, media and a carousel end to end through `fapost/connector-viber`.

- [ ] Viber connector — `fapost/connector-viber` (MIT): ingress with the body HMAC keyed by the token, sender,
      webhook registration, profile with `chatURI`, media by link, `rich_media` carousels within Viber's 6 cards
      and 30 KB; the reversal recorded in `channel-connectors` (after: Carousel node — the carousel it renders
      comes from the node)

## Phase 3 — Cards from data

Goal: a carousel built from a list in a flow variable, like dynamic buttons. Done when: a list fetched by a
`call` node becomes a carousel, and a press saves the chosen item.

- [ ] Carousel from a list — the node's data source: a list path, a mapping of item fields to card fields, stable
      ids per item, the chosen item saved on a press; validation where the count is unknown (after: Carousel
      node — the source feeds the same node and snapshot)

## Phase 4 — Telegram paged carousel

Goal: Telegram shows one card at a time in a single message, paged in place. Done when: ◀ ▶ page a carousel in
Telegram without the flow seeing the presses, and the buttons sit under their card.

- [ ] fog: Telegram paging — a seam for presses the connector handles itself, page state per message,
      `answerCallbackQuery`, `editMessageMedia`, edit races; cannot be stated precisely until the state question
      is answered

## Later

- [ ] fog: a picture per channel type for one card
- [ ] fog: Messenger, Instagram, WhatsApp and Teams carousels — each with its connector

## Waves

1. Carousel contract
2. Carousel node; Carousel pictures; Slack carousel
3. Carousel editor; Carousel fallback; Viber connector
4. Carousel docs; Carousel from a list
