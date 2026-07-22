# Send Message Node Task

## Purpose

Node `send_message` delivers a message to the contact through the active channel (Telegram, WhatsApp).

Execution mode depends on node configuration:

- **No buttons** → fire-and-forget, returns `sourceHandle: default` immediately.
- **Buttons with `keyboard_mode = inline`** → waiting node, session pauses until contact presses a button or timeout
  occurs.
- **Buttons with `keyboard_mode = reply`** → fire-and-forget, returns `sourceHandle: default` immediately. Node is
  terminal — buttons are a UX hint only, user response is handled by the global message pipeline (TriggerResolver /
  another active session), not by this node.

---

## Content types

Supported `content_type` values:

- `text` — plain text message
- `text_with_keyboard` — text with inline or reply keyboard buttons
- `image` — image with optional caption
- `document` — file with optional caption
- `video` — video with optional caption
- `voice` — audio message

`content_type` is required in config.

If `content_type` is absent or unsupported — handler must fail with a domain exception, not silently skip.

---

## Config shape per content type

### text

```json
{
  "content_type": "text",
  "text": {
    "en": "Hello",
    "uk": "Привіт"
  }
}
```

### text_with_keyboard

```json
{
  "content_type": "text_with_keyboard",
  "keyboard_mode": "inline",
  "text": {
    "en": "Choose an option"
  },
  "buttons": [
    {
      "id": "uuid-stable",
      "type": "callback",
      "label": {
        "en": "Yes",
        "uk": "Так"
      },
      "value": "yes",
      "order": 0,
      "row": 0
    }
  ]
}
```

`keyboard_mode` is **required** when `content_type = text_with_keyboard`. Values: `inline` | `reply`.

### image / document / video

```json
{
  "content_type": "image",
  "media_url": "https://...",
  "caption": {
    "en": "Photo caption"
  }
}
```

### voice

```json
{
  "content_type": "voice",
  "media_url": "https://..."
}
```

---

## Keyboard modes

Two mutually exclusive modes for `text_with_keyboard`. Selected via `keyboard_mode` field.

### `inline` mode

- Buttons rendered as Telegram InlineKeyboard / WhatsApp interactive buttons.
- Each press produces a `callback_query` with `callback_data` encoding `session_id + button_id`.
- Node becomes **waiting** — session pauses until press or timeout.
- Node has one output per button `value` + optional `no_response`.
- Node can have connected outgoing edges — buttons drive flow continuation.

### `reply` mode

- Buttons rendered as Telegram ReplyKeyboard (one-time, auto-resize).
- On press, Telegram sends a regular `message` with the button's label as text — no callback, no `session_id`, no
  `button_id` delivered.
- Node is **fire-and-forget** — returns `sourceHandle: default` immediately after send.
- Node is **terminal** — its `default` output must not be connected. The contact's reply is handled by the global
  message pipeline (TriggerResolver picks up the keyword, or it hits an active session in another node).
- No waiting, no label→value matching inside the node, no session snapshot of labels.
- `timeout_seconds` is ignored.

**Channel-agnosticism.** Flow definition does not validate `keyboard_mode` against channel type. Channel adapter decides
how to degrade:

- `TelegramAdapter` implements both modes natively.
- `WhatsAppAdapter` implements `inline` via interactive buttons; for `reply` it degrades (e.g., renders options as a
  text list without an actual keyboard). Exact fallback form — adapter's decision.

---

## Buttons

### Button properties

| Field | Type | Description |
|-------|------|-------------|
| `id` | UUID | Stable identifier. Generated once on creation. Never changes. |
| `type` | `callback` \| `reply` | Keyboard type. |
| `label` | multilingual object | Displayed text. Translated per language. |
| `value` | string | Business value saved to state on press (inline mode only). Never translated. |
| `order` | int | Position within the row. |
| `row` | int | Row index. Buttons with same row appear on same line. |

`value` is language-agnostic — always stored as-is regardless of contact language.

`label` is translated — never stored in state.

`id` must be UUID. Stable forever — used in `callback_data`. Changing `id` breaks existing sessions.

### `id` / `value` in `reply` mode

`id` and `value` are irrelevant at runtime in `reply` mode — no resume, no state write. They are still generated and
persisted in the JSON snapshot to keep a uniform button shape across modes and to simplify mode switching in the
builder. Builder UI hides these fields when `keyboard_mode = reply`.

### Button types (field `type`)

- `callback` → used with `keyboard_mode = inline`
- `reply` → used with `keyboard_mode = reply`

Builder sets this field automatically based on `keyboard_mode`.

---

## Template variables in text fields

Text fields support template interpolation:

```
{{flow.name}}
{{system.contact_id}}
{{rag.answer}}
{{module.hr.department}}
```

Template resolution happens at send time, not at node definition time.

---

## Multilingual content

`text`, `caption`, `label` fields are multilingual objects:

```json
{
  "en": "Hello",
  "uk": "Привіт",
  "ru": "Привет"
}
```

Resolution chain at send time:

```
session.state.system.language
  ?? contact.language
  ?? assistant.default_language
  ?? tenant_settings.fallback_language
```

Resolved via `LanguageResolverInterface`. Direct language checks inside the handler are forbidden.

If locale not found in the field — fall back to `assistant.default_language`, then `fallback_language`.

---

## Idempotency

Handler checks `system.sent_messages` state before sending:

```
if node_id in state[system.sent_messages]:
    return Executed with sourceHandle: default (skip send)
```

After send — writes `{ node_id: external_message_id }` to `system.sent_messages`.

This makes the handler safe to retry without duplicate messages.

In `inline` mode: idempotent skip still enters Waiting status (session must wait for the button press).
In `reply` mode: idempotent skip returns `default` immediately, no state change.

---

## Outputs

### No buttons (`content_type != text_with_keyboard`)

| Handle | When |
|--------|------|
| `default` | Always — after successful send or idempotent skip |

### `keyboard_mode = inline`

One output per button `value` + `no_response` for timeout:

| Handle | When |
|--------|------|
| `{button.value}` | Contact pressed the button with this value |
| `no_response` | Timeout elapsed, no button pressed |

Button `value` is used as `sourceHandle` — values must be unique within the node.

If contact presses a button whose value has no connected edge — session ends (no next node).

### `keyboard_mode = reply`

| Handle | When |
|--------|------|
| `default` | Always — immediately after send |

Node is terminal. `default` output must not be connected in the flow definition (validation below).

---

No error output in any mode. If send fails — handler must throw, engine handles retry.

---

## Button press execution flow (inline mode only)

```
1. Handler sends message → status: Waiting
2. Session pauses — engine stops processing
3. Contact presses button → inbound webhook received
4. Engine resumes session by session_id from callback_data
5. Engine reads pressed button_id → resolves button value
6. Routes to output matching button value
7. Execution continues from that output
```

### `callback_data` structure

`callback_data` stored on each Telegram InlineKeyboard button must encode:

```json
{
  "session_id": "ulid",
  "button_id": "uuid"
}
```

`button_id` is resolved to `value` at resume time by reading the node config from the flow snapshot.

`value` is never stored in `callback_data` — only stable `button_id`. This allows label and value changes without
breaking active sessions.

### Idempotency in waiting mode (inline)

Before sending: check `system.sent_messages` — if already sent, skip send but still enter Waiting status (session must
still wait for the button press).

After button press: mark button press in state to prevent duplicate routing on retry.

---

## Timeout (no_response) — inline mode only

Optional `timeout_seconds` field in config. Applies only when `keyboard_mode = inline`.

If set and no button pressed within the window — engine routes to `no_response` output.

If not set — session waits indefinitely (until contact presses or session expires globally).

In `reply` mode `timeout_seconds` is ignored (node is not waiting).

```json
{
  "content_type": "text_with_keyboard",
  "keyboard_mode": "inline",
  "text": {
    "en": "Choose:"
  },
  "buttons": [
    ...
  ],
  "timeout_seconds": 3600
}
```

---

## Flow-definition validation

On flow snapshot save/activation:

```
foreach node where type = send_message AND keyboard_mode = reply:
    if edge exists WHERE from_node = node.id AND sourceHandle = 'default':
        throw FlowDefinitionValidationException(
            "send_message node with keyboard_mode=reply must be terminal — 'default' output cannot be connected"
        )
```

Error surfaces in builder at activation time.

---

## Channel adapter contract

```php
interface ChannelAdapterInterface
{
    // ...existing

    public function sendMessage(
        OutboundMessage $message,
        KeyboardMode $keyboardMode = KeyboardMode::Inline
    ): SentMessageResult;
}
```

Adapter decides rendering per mode:

- **TelegramAdapter**
    - `inline` → `InlineKeyboardMarkup` with `callback_data` per button
    - `reply` → `ReplyKeyboardMarkup` with `one_time_keyboard: true`, `resize_keyboard: true` (no config flags — fixed
      behavior)
- **WhatsAppAdapter**
    - `inline` → interactive buttons
    - `reply` → degraded fallback (implementation detail of the adapter — e.g., options listed in text body). Exact form
      is defined per adapter, not per flow.

If `OutboundMessage` has no buttons, `keyboardMode` has no effect (plain text is sent).

---

## KeyboardMode enum

Lives in `fapost/foundation` — must be importable by external plugins implementing `ChannelAdapterInterface`.

```php
namespace FAPost\Foundation\Flow\Enums;

enum KeyboardMode: string
{
    case Inline = 'inline';
    case Reply  = 'reply';
}
```

---

## Current implementation gaps

The existing `SendMessageNodeHandler` handles only `text` and inline `buttons`.

Missing:

- `content_type` field — not yet in config schema or send logic
- `keyboard_mode` field — not yet in config schema
- `image`, `document`, `video`, `voice` content types
- Button `row` / `order` layout rendering
- `caption` field for media types
- Reply-keyboard rendering path in `TelegramAdapter`
- Reply-keyboard degradation in `WhatsAppAdapter`
- Flow-definition validator rule for terminal `reply` nodes

`MessageSenderInterface` and channel adapters need to support structured payload per `content_type` and `keyboard_mode`.

---

## Builder UI requirements

Config panel for `send_message`:

- Dropdown: content type selector
- Dropdown: `keyboard_mode` (visible only when `content_type = text_with_keyboard`) — `inline` | `reply`
- Text area: message body (multilingual — per language tab)
- Button editor (only for `text_with_keyboard`):
    - Add / remove buttons
    - Set label (per language), row, order
    - `value` field — visible only in `inline` mode
    - `id` — auto-generated on add, never editable, not shown
    - `type` — set automatically from `keyboard_mode`, not user-facing
- Media URL field (for image / document / video / voice)
- Caption field (for image / document / video, multilingual)
- `timeout_seconds` field — visible only in `inline` mode with buttons

Preview card in sequence editor shows:

- Content type icon
- First language text truncated
- Button count if any
- Badge "reply keyboard" when `keyboard_mode = reply`

### Mode switching

When user switches `keyboard_mode` from `inline` → `reply`:

- If node has any outgoing edges → show confirm dialog: "All outgoing connections will be removed. Continue?" → on OK
  remove edges.
- Hide `value` and `timeout_seconds` fields.

When user switches from `reply` → `inline`:

- Unhide `value` and `timeout_seconds`.
- Existing buttons keep their `id` and (auto-generated placeholder or empty) `value` — user must fill values before
  activation.

### Branching rendering in sequence editor

- **`inline` mode**: node renders as a branching node. Each button appears as a named output port labeled with button
  `value`. `no_response` output shown if `timeout_seconds` is set. Edges connect from button outputs to next nodes.
  Adding a button adds a port; removing a button removes the port and its edge.
- **`reply` mode**: node renders with a single `default` output port, visually marked as terminal (e.g., greyed out, "
  end of branch" indicator). Port is not connectable.

---

## Notes

### Persistent reply keyboard (out of scope)

Current `reply` mode always uses `one_time_keyboard: true` — keyboard hides after first press. A persistent menu
keyboard (always-visible, branch-aware) is deferred to a future dedicated node type. Supporting both persistent and
one-time in the same node would require storing branch context to disambiguate keyword matches across different flow
branches, which is not justified at this stage.

### Residual keyboard after flow end

In `reply` mode, if the flow ends without the user pressing any button, the reply keyboard stays visible in Telegram
until the next message without `reply_markup` arrives. No explicit cleanup is needed — Telegram clears it on the next
outbound message sent without keyboard markup.
